<?php

namespace App\Tests\Api;

use App\Entity\User;
use App\Tests\Support\ApiTestCase;
use App\Validator\ReservedPseudonym;
use PHPUnit\Framework\Attributes\DataProvider;

class RegistrationTest extends ApiTestCase
{
    private function register(array $data): void
    {
        // Les CGU sont obligatoires : acceptées par défaut sauf si le test les fournit
        $data += ['terms_accepted' => true];
        $this->post($data);
    }

    private function post(array $data): void
    {
        $this->client->request('POST', '/api/register', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode($data));
    }

    public function testInscriptionValide(): void
    {
        $this->register(['pseudonym' => '  Jeanne  ', 'email' => 'Jeanne@Example.COM', 'password' => 'secret123']);

        $this->assertResponseStatusCodeSame(201);
        $data = $this->json();
        $this->assertSame(['id', 'pseudonym', 'email', 'roles', 'emailVerified', 'pendingEmail'], array_keys($data['user']));
        $this->assertSame('Jeanne', $data['user']['pseudonym']);
        $this->assertSame('jeanne@example.com', $data['user']['email']);
        $this->assertSame(['ROLE_USER'], $data['user']['roles']);
        $this->assertStringNotContainsString('secret123', $this->client->getResponse()->getContent());

        $user = $this->em->getRepository(User::class)->findOneBy(['email' => 'jeanne@example.com']);
        $this->assertNotSame('secret123', $user->getPassword());
        $this->assertSame('Jeanne', $user->getDisplayName());
    }

    public function testLesCguSontObligatoires(): void
    {
        foreach ([[], ['terms_accepted' => false], ['terms_accepted' => 'true']] as $terms) {
            // post() n'ajoute rien : le premier cas envoie un corps sans le champ
            $this->post(['pseudonym' => 'Jeanne', 'email' => 'a@example.com', 'password' => 'secret123'] + $terms);

            $this->assertResponseStatusCodeSame(422);
            $this->assertArrayHasKey('terms_accepted', $this->json()['errors']);
        }
        $this->assertNull($this->em->getRepository(User::class)->findOneBy(['email' => 'a@example.com']));
    }

    public function testLaDateDAcceptationEstEnregistree(): void
    {
        $this->register(['pseudonym' => 'Jeanne', 'email' => 'a@example.com', 'password' => 'secret123']);

        $user = $this->em->getRepository(User::class)->findOneBy(['email' => 'a@example.com']);
        $this->assertNotNull($user->getTermsAcceptedAt());
    }

    public function testLePseudonymeNEstPasUnique(): void
    {
        $this->register(['pseudonym' => 'Jeanne', 'email' => 'a@example.com', 'password' => 'secret123']);
        $this->assertResponseStatusCodeSame(201);
        $this->register(['pseudonym' => 'Jeanne', 'email' => 'b@example.com', 'password' => 'secret123']);
        $this->assertResponseStatusCodeSame(201);
    }

    public function testEmailDejaUtilise409MemeEnCasseDifferente(): void
    {
        $this->register(['pseudonym' => 'Jeanne', 'email' => 'a@example.com', 'password' => 'secret123']);
        $this->register(['pseudonym' => 'Autre', 'email' => 'A@Example.com', 'password' => 'secret123']);

        $this->assertResponseStatusCodeSame(409);
        $this->assertArrayHasKey('error', $this->json());
    }

    public function testErreursDeValidationParChamp(): void
    {
        $this->register(['pseudonym' => 'a', 'email' => 'pas-un-email', 'password' => '123']);

        $this->assertResponseStatusCodeSame(422);
        $data = $this->json();
        $this->assertSame('Données invalides', $data['message']);
        $this->assertSame(['pseudonym', 'email', 'password'], array_keys($data['errors']));
        $this->assertIsArray($data['errors']['pseudonym']);
    }

    public function testChampsAbsents(): void
    {
        $this->register([]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertEqualsCanonicalizing(['pseudonym', 'email', 'password'], array_keys($this->json()['errors']));
    }

    public function testPseudonymeTropLong(): void
    {
        $this->register(['pseudonym' => str_repeat('a', 31), 'email' => 'a@example.com', 'password' => 'secret123']);
        $this->assertResponseStatusCodeSame(422);
        $this->assertArrayHasKey('pseudonym', $this->json()['errors']);
    }

    #[DataProvider('reserves')]
    public function testPseudonymeReserve(string $pseudonym, bool $refuse): void
    {
        $this->register(['pseudonym' => $pseudonym, 'email' => 'a@example.com', 'password' => 'secret123']);

        $this->assertResponseStatusCodeSame($refuse ? 422 : 201);
        if ($refuse) {
            $this->assertSame(['pseudonym'], array_keys($this->json()['errors']));
        }
    }

    public static function reserves(): iterable
    {
        foreach (['admin', 'ADMIN', 'Administrateur', 'Modération', 'moderateur', 'Équipe', 'a d m i n', 'Aupa-ravant', 'sys_tem', 'Support'] as $p) {
            yield $p => [$p, true];
        }
        foreach (['adm1n', 'admin2', 'admins', 'monadmin', 'support-client'] as $p) {
            yield $p => [$p, false];
        }
    }

    public function testNormalisation(): void
    {
        $this->assertSame('moderation', ReservedPseudonym::normalize(' Modé-ration_'));
    }
}
