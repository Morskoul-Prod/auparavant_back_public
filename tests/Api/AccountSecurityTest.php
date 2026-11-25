<?php

namespace App\Tests\Api;

use App\Entity\User;
use App\Tests\Support\ApiTestCase;

class AccountSecurityTest extends ApiTestCase
{
    private function call(string $method, string $suffix, User $target, array $body, ?User $as = null, ?string $token = null): void
    {
        $auth = $token !== null ? ['HTTP_AUTHORIZATION' => 'Bearer ' . $token] : $this->authHeaders($as ?? $target);
        $this->client->request($method, '/api/secure/' . ($method === 'DELETE' ? 'delete' : 'update') . '/user/' . $target->getId() . $suffix, server: $auth + ['CONTENT_TYPE' => 'application/json'], content: json_encode($body));
    }

    private function fresh(User $user): User
    {
        $this->em->clear();

        return $this->em->getRepository(User::class)->find($user->getId());
    }

    private function user(): User
    {
        $user = $this->createUser();
        $this->givePassword($user);

        return $user;
    }

    // --- email ---

    public function testChangementDEmailMetLaNouvelleAdresseEnAttente(): void
    {
        $user = $this->user();
        $old = $user->getEmail();
        $this->call('PUT', '/email', $user, ['email' => '  Nouveau@Example.COM ', 'currentPassword' => 'secret123']);

        $this->assertResponseStatusCodeSame(200);
        $data = $this->json();
        // L'adresse du compte ne change qu'à la confirmation
        $this->assertSame($old, $data['user']['email']);
        $this->assertSame('nouveau@example.com', $data['pendingEmail']);
        $this->assertEqualsCanonicalizing(['id', 'displayName', 'pseudonym', 'email', 'roles', 'emailVerified'], array_keys($data['user']));
        $this->assertArrayNotHasKey('token', $data);
        $this->assertStringNotContainsString($this->fresh($user)->getPassword(), $this->client->getResponse()->getContent());
        $this->assertSame($old, $this->fresh($user)->getEmail());
    }

    public function testEmailMauvaisMotDePasse(): void
    {
        $user = $this->user();
        $email = $user->getEmail();
        $this->call('PUT', '/email', $user, ['email' => 'nouveau@example.com', 'currentPassword' => 'faux']);

        $this->assertResponseStatusCodeSame(422);
        $this->assertSame(['currentPassword'], array_keys($this->json()['errors']));
        $this->assertSame($email, $this->fresh($user)->getEmail());
    }

    public function testEmailMotDePasseAbsentEtEmailInvalide(): void
    {
        $user = $this->user();
        $this->call('PUT', '/email', $user, ['email' => 'pas-un-email']);

        $this->assertResponseStatusCodeSame(422);
        $this->assertEqualsCanonicalizing(['currentPassword', 'email'], array_keys($this->json()['errors']));
    }

    public function testEmailDejaPris409(): void
    {
        $user = $this->user();
        $other = $this->createUser();
        $this->call('PUT', '/email', $user, ['email' => strtoupper($other->getEmail()), 'currentPassword' => 'secret123']);

        $this->assertResponseStatusCodeSame(409);
    }

    public function testEmailDejaPrisAvecMauvaisMotDePasseNeRevelePasLExistence(): void
    {
        $user = $this->user();
        $other = $this->createUser();
        $this->call('PUT', '/email', $user, ['email' => $other->getEmail(), 'currentPassword' => 'faux']);

        $this->assertResponseStatusCodeSame(422);
    }

    // --- mot de passe ---

    public function testChangementDeMotDePasseReussi(): void
    {
        $user = $this->user();
        $old = $this->jwtFor($user);
        $this->call('PUT', '/password', $user, ['currentPassword' => 'secret123', 'newPassword' => 'nouveau-secret']);

        $this->assertResponseStatusCodeSame(200);
        $this->assertStringNotContainsString('nouveau-secret', $this->client->getResponse()->getContent());
        $hash = $this->fresh($user)->getPassword();
        $this->assertNotSame('nouveau-secret', $hash);
        $hasher = static::getContainer()->get(\Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface::class);
        $this->assertTrue($hasher->isPasswordValid($this->fresh($user), 'nouveau-secret'));

        // Connexion avec le nouveau mot de passe ; l'ancien jeton est refusé
        $this->client->request('POST', '/api/login_check', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['username' => $user->getEmail(), 'password' => 'nouveau-secret']));
        $this->assertResponseIsSuccessful();
        $this->client->request('GET', '/api/secure/me', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $old]);
        $this->assertResponseStatusCodeSame(401);
    }

    public function testMotDePasseMauvaisMotDePasseActuel(): void
    {
        $user = $this->user();
        $hash = $user->getPassword();
        $this->call('PUT', '/password', $user, ['currentPassword' => 'faux', 'newPassword' => 'nouveau-secret']);

        $this->assertResponseStatusCodeSame(422);
        $this->assertSame(['currentPassword'], array_keys($this->json()['errors']));
        $this->assertSame($hash, $this->fresh($user)->getPassword());
    }

    public function testMotDePasseTropFaible(): void
    {
        $user = $this->user();
        $this->call('PUT', '/password', $user, ['currentPassword' => 'secret123', 'newPassword' => '123']);

        $this->assertResponseStatusCodeSame(422);
        $this->assertSame(['newPassword'], array_keys($this->json()['errors']));
    }

    // --- suppression ---

    public function testSuppressionExigeLeMotDePasse(): void
    {
        $user = $this->user();
        $this->call('DELETE', '', $user, []);
        $this->assertResponseStatusCodeSame(422);
        $this->assertSame(['currentPassword'], array_keys($this->json()['errors']));

        $this->call('DELETE', '', $user, ['currentPassword' => 'faux']);
        $this->assertResponseStatusCodeSame(422);
        $this->assertNotNull($this->fresh($user));
    }

    public function testSuppressionReussie(): void
    {
        $user = $this->user();
        $id = $user->getId();
        $this->call('DELETE', '', $user, ['currentPassword' => 'secret123']);

        $this->assertResponseStatusCodeSame(200);
        $this->assertNull($this->em->getRepository(User::class)->find($id));
    }

    // --- commun ---

    public function testCompteDUnAutre403SurLesTroisRoutes(): void
    {
        $user = $this->user();
        $other = $this->user();

        foreach ([['PUT', '/email', ['email' => 'x@y.fr', 'currentPassword' => 'secret123']],
            ['PUT', '/password', ['currentPassword' => 'secret123', 'newPassword' => 'nouveau-secret']],
            ['DELETE', '', ['currentPassword' => 'secret123']]] as [$method, $suffix, $body]) {
            $this->call($method, $suffix, $other, $body, $user);
            $this->assertResponseStatusCodeSame(403, $method . $suffix);
        }
        $this->assertNotNull($this->fresh($other));
    }

    public function testUtilisateurInexistant404EtAnonyme401(): void
    {
        $user = $this->user();
        $this->client->request('PUT', '/api/secure/update/user/999999/email', server: $this->authHeaders($user) + ['CONTENT_TYPE' => 'application/json'], content: '{}');
        $this->assertResponseStatusCodeSame(404);

        $this->client->request('PUT', '/api/secure/update/user/' . $user->getId() . '/password', server: ['CONTENT_TYPE' => 'application/json'], content: '{}');
        $this->assertResponseStatusCodeSame(401);
    }

    public function testBruteForceLimiteEn429AvecRetryAfter(): void
    {
        $user = $this->user();
        for ($i = 0; $i < 5; ++$i) {
            $this->call('PUT', '/password', $user, ['currentPassword' => 'faux' . $i, 'newPassword' => 'nouveau-secret']);
            $this->assertResponseStatusCodeSame(422);
        }

        // La 6e tentative est bloquée, même avec le bon mot de passe
        $this->call('PUT', '/password', $user, ['currentPassword' => 'secret123', 'newPassword' => 'nouveau-secret']);
        $this->assertResponseStatusCodeSame(429);
        $retry = (int) $this->client->getResponse()->headers->get('Retry-After');
        $this->assertGreaterThan(0, $retry);
        $this->assertSame($retry, $this->json()['retryAfter']);

        // Le compteur est partagé entre les trois routes
        $this->call('DELETE', '', $user, ['currentPassword' => 'secret123']);
        $this->assertResponseStatusCodeSame(429);
    }

    public function testRetryAfterEstExposeEnCors(): void
    {
        $user = $this->user();
        $this->client->request('PUT', '/api/secure/update/user/' . $user->getId() . '/password', server: $this->authHeaders($user) + ['CONTENT_TYPE' => 'application/json', 'HTTP_ORIGIN' => 'http://localhost:5173'], content: '{}');
        $this->assertSame('Retry-After', $this->client->getResponse()->headers->get('Access-Control-Expose-Headers'));
    }
}
