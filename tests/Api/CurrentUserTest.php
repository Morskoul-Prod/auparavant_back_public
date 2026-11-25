<?php

namespace App\Tests\Api;

use App\Tests\Support\ApiTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class CurrentUserTest extends ApiTestCase
{
    public function testLeLoginRenvoieLesRolesDeLUtilisateurConnecte(): void
    {
        $user = $this->createUser(['ROLE_USER', 'ROLE_MODERATOR']);
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, 'secret123'));
        $this->em->flush();

        $this->client->request('POST', '/api/login_check', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['username' => $user->getEmail(), 'password' => 'secret123']));

        $this->assertResponseIsSuccessful();
        $this->assertSame(['ROLE_USER', 'ROLE_MODERATOR'], $this->json()['data']['roles']);
        $this->assertSame($user->getId(), $this->json()['data']['id']);
        $this->assertSame(['id', 'pseudonym', 'email', 'roles', 'emailVerified', 'pendingEmail'], array_keys($this->json()['data']));
        $this->assertStringNotContainsString('secret123', $this->client->getResponse()->getContent());
    }

    public function testMeRenvoieLIdentiteEtLesRoles(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);

        $this->client->request('GET', '/api/secure/me', server: $this->authHeaders($admin));

        $this->assertResponseIsSuccessful();
        $this->assertSame(['id' => $admin->getId(), 'pseudonym' => 'Testeur', 'email' => $admin->getEmail(), 'roles' => ['ROLE_ADMIN'], 'emailVerified' => true, 'pendingEmail' => null], $this->json());
    }

    public function testMeExigeUnJwt(): void
    {
        $this->client->request('GET', '/api/secure/me');
        $this->assertResponseStatusCodeSame(401);
    }
}
