<?php

namespace App\Tests\Support;

use App\Entity\Location;
use App\Entity\Memory;
use App\Entity\Picture;
use App\Entity\Place;
use App\Entity\User;
use App\Enum\PictureStatus;
use App\Enum\PlaceType;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Mime\Email;

/**
 * Base des tests fonctionnels : chaque test s'exécute dans une transaction annulée à la fin
 * (les fichiers uploadés vont dans var/test-uploads, vidé après chaque test).
 */
abstract class ApiTestCase extends WebTestCase
{
    protected KernelBrowser $client;
    protected EntityManagerInterface $em;
    private int $sequence = 0;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot(); // même connexion pour tout le test, nécessaire au rollback
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();
        // Les compteurs du limiteur (anti-spam) persistent sur disque : on repart de zéro à chaque test
        static::getContainer()->get('cache.rate_limiter')->clear();
    }

    protected function tearDown(): void
    {
        $this->em->getConnection()->rollBack();
        (new Filesystem())->remove(static::getContainer()->getParameter('kernel.project_dir') . '/var/test-uploads');
        parent::tearDown();
    }

    /** Les utilisateurs de test ont une adresse confirmée, sauf demande contraire ($verified = false). */
    protected function createUser(array $roles = ['ROLE_USER'], bool $verified = true): User
    {
        $user = (new User())
            ->setEmail(sprintf('user%d-%s@test.local', ++$this->sequence, uniqid()))
            ->setPseudonym('Testeur')
            ->setRoles($roles)
            ->setPassword('inutile');
        if ($verified) {
            $user->setEmailVerifiedAt(new \DateTimeImmutable());
        }
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /** Dernier email envoyé pendant la requête (échoue s'il n'y en a pas) */
    protected function email(): Email
    {
        $message = $this->getMailerMessage();
        $this->assertInstanceOf(Email::class, $message);

        return $message;
    }

    /** Donne un vrai mot de passe haché à l'utilisateur (createUser met un mot de passe inutilisable). */
    protected function givePassword(User $user, string $password = 'secret123'): void
    {
        // Après une requête le gestionnaire d'entités est vidé : on repasse par l'entité gérée
        $managed = $this->em->find(User::class, $user->getId());
        $managed->setPassword(static::getContainer()->get(\Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface::class)->hashPassword($managed, $password));
        $this->em->flush();
    }

    /** Supprime le compte via l'API (mot de passe actuel fourni). */
    protected function deleteAccount(User $user): void
    {
        $this->givePassword($user);
        $this->client->request('DELETE', '/api/secure/delete/user/' . $user->getId(), server: $this->authHeaders($user) + ['CONTENT_TYPE' => 'application/json'], content: json_encode(['currentPassword' => 'secret123']));
        $this->assertResponseIsSuccessful();
    }

    /**
     * Souvenir avec une photo « avant » approuvée, éventuellement une photo « after »
     */
    protected function createMemory(User $author, ?PictureStatus $afterStatus = null, ?User $afterUploader = null): Memory
    {
        $location = (new Location())
            ->setArea('Île-de-France')->setDepartment('Paris')->setStreet('Place du Panthéon')
            ->setCity('Paris')->setZipcode(75005)->setLatitude('48.846198')->setLongitude('2.346105');
        $place = (new Place())->setName('Panthéon')->setType(PlaceType::MAISON)->setLocation($location);
        $memory = (new Memory())
            ->setUser($author)->setLocation($location)->setPlace($place)
            ->setTitle('Le Panthéon')->setContent('Photo de famille devant le Panthéon')->setYear(1950);

        $memory->addPicture($this->createPicture('before.png', Picture::TYPE_BEFORE, 0, PictureStatus::APPROVED, $author));
        if ($afterStatus) {
            $memory->addPicture($this->createPicture('after.png', Picture::TYPE_AFTER, 1, $afterStatus, $afterUploader ?? $author));
        }

        $this->em->persist($location);
        $this->em->persist($place);
        $this->em->persist($memory);
        $this->em->flush();

        return $memory;
    }

    /** Photo sans fichier physique (seul le nom est utile pour la sérialisation). */
    protected function createPicture(string $name, string $type, int $position, PictureStatus $status, ?User $uploader): Picture
    {
        $picture = (new Picture())
            ->setType($type)->setPosition($position)->setStatus($status)->setUploadedBy($uploader);
        $picture->setImageName($name);

        return $picture;
    }

    /** Crée le fichier physique d'une photo dans le dossier d'upload de test et renvoie son chemin. */
    protected function storeFile(Picture $picture): string
    {
        $dir = static::getContainer()->getParameter('kernel.project_dir') . '/var/test-uploads';
        (new Filesystem())->mkdir($dir);
        file_put_contents($path = $dir . '/' . $picture->getImageName(), 'x');

        return $path;
    }

    protected function jwtFor(User $user): string
    {
        return static::getContainer()->get(JWTTokenManagerInterface::class)->create($user);
    }

    /** @return array<string, string> en-têtes d'authentification JWT */
    protected function authHeaders(User $user): array
    {
        return ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->jwtFor($user)];
    }

    /** Vrai petit PNG envoyé comme fichier uploadé. */
    protected function pngFile(string $name = 'photo.png'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'png');
        file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));

        return new UploadedFile($path, $name, 'image/png', null, true);
    }

    protected function textFile(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'txt');
        file_put_contents($path, 'pas une image');

        return new UploadedFile($path, 'faux.png', 'image/png', null, true);
    }

    protected function json(): array
    {
        return json_decode($this->client->getResponse()->getContent(), true);
    }

    /** Appelle un endpoint multipart de création/modification de souvenir. */
    protected function postMemoryForm(string $uri, User $user, array $data, array $files, array $meta): void
    {
        $this->client->request('POST', $uri, [
            'data' => json_encode($data),
            'pictures_meta' => json_encode($meta),
        ], ['pictures' => $files], $this->authHeaders($user));
    }
}
