<?php

namespace App\Tests\Back;

use App\Entity\User;
use App\Tests\Support\ApiTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Création d'un utilisateur depuis le back-office : mot de passe haché, aucun droit par défaut.
 */
class UserCreationTest extends ApiTestCase
{
    private function submit(array $values): void
    {
        $this->client->loginUser($this->createUser(['ROLE_ADMIN']));
        $crawler = $this->client->request('GET', '/back/user/new');
        $form = $crawler->selectButton('Enregistrer')->count() ? $crawler->selectButton('Enregistrer')->form() : $crawler->filter('form button[type=submit]')->last()->form();
        $this->client->submit($form, $values);
    }

    public function testLeMotDePasseEstHacheEtLeCompteNAAucunDroit(): void
    {
        $this->submit(['user[pseudonym]' => 'Nouveau', 'user[email]' => 'nouveau@example.fr', 'user[password]' => 'secret123']);

        $this->assertResponseRedirects('/back/user/');
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => 'nouveau@example.fr']);
        $this->assertNotNull($user);
        $this->assertNotSame('secret123', $user->getPassword());
        $this->assertTrue(static::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($user, 'secret123'));
        $this->assertSame(['ROLE_USER'], $user->getRoles());
    }

    public function testMotDePasseTropCourtRefuse(): void
    {
        $this->submit(['user[pseudonym]' => 'Court', 'user[email]' => 'court@example.fr', 'user[password]' => '123']);

        $this->assertResponseStatusCodeSame(422);
        $this->assertNull($this->em->getRepository(User::class)->findOneBy(['email' => 'court@example.fr']));
    }

    /** Rendu de la page 403 de l'application (en test, le profileur de Symfony la remplace sur une vraie requête) */
    private function page403(User $user): string
    {
        $container = static::getContainer();
        $container->get('security.token_storage')->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));

        return $container->get('twig')->render('bundles/TwigBundle/Exception/error403.html.twig');
    }

    public function testUnCompteSansRoleVoitUnMessageExplicite(): void
    {
        $this->assertStringContainsString('Un administrateur doit vous attribuer un rôle', $this->page403($this->createUser(['ROLE_USER'])));
    }

    public function testUnModerateurNAPasCeMessage(): void
    {
        $html = $this->page403($this->createUser(['ROLE_MODERATOR']));

        $this->assertStringNotContainsString('Un administrateur doit vous attribuer un rôle', $html);
        $this->assertStringContainsString('pas les droits pour accéder à cette page', $html);
    }

    public function testUnAnonymeVoitLeMessageGenerique(): void
    {
        static::getContainer()->get('security.token_storage')->setToken(null);
        $html = static::getContainer()->get('twig')->render('bundles/TwigBundle/Exception/error403.html.twig');

        $this->assertStringNotContainsString('Un administrateur doit vous attribuer un rôle', $html);
    }
}
