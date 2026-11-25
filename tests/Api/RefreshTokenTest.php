<?php

namespace App\Tests\Api;

use App\Entity\RefreshToken;
use App\Entity\User;
use App\Repository\RefreshTokenRepository;
use App\Service\RefreshTokenCookie;
use App\Tests\Support\ApiTestCase;
use Symfony\Component\BrowserKit\Cookie as BrowserCookie;
use Symfony\Component\HttpFoundation\Cookie;

class RefreshTokenTest extends ApiTestCase
{
    private const ORIGIN = 'http://localhost:5173';

    /** Connexion via l'API ; renvoie le refresh token en clair posé en cookie. */
    private function login(User $user): string
    {
        $this->givePassword($user);
        $this->client->request('POST', '/api/login_check', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['username' => $user->getEmail(), 'password' => 'secret123']));
        $this->assertResponseIsSuccessful();

        return $this->refreshCookie()->getValue();
    }

    private function refreshCookie(): Cookie
    {
        foreach ($this->client->getResponse()->headers->getCookies() as $cookie) {
            if ($cookie->getName() === RefreshTokenCookie::NAME) {
                return $cookie;
            }
        }
        $this->fail('Cookie refresh_token absent de la réponse');
    }

    /** Joint le cookie au prochain appel, comme le ferait le navigateur. */
    private function withCookie(?string $token): void
    {
        $this->client->getCookieJar()->clear();
        if ($token !== null) {
            $this->client->getCookieJar()->set(new BrowserCookie(RefreshTokenCookie::NAME, $token, null, RefreshTokenCookie::PATH));
        }
    }

    private function refresh(?string $token, array $server = ['HTTP_ORIGIN' => self::ORIGIN]): void
    {
        $this->withCookie($token);
        $this->client->request('POST', '/api/token/refresh', server: $server);
    }

    public function testLeLoginPoseUnCookieHttpOnlyLimiteAuCheminDuRefresh(): void
    {
        $token = $this->login($this->createUser());
        $cookie = $this->refreshCookie();

        $this->assertSame(64, strlen($token));
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame(RefreshTokenCookie::PATH, $cookie->getPath());
        $this->assertSame(Cookie::SAMESITE_LAX, $cookie->getSameSite());
        $this->assertStringNotContainsString($token, $this->client->getResponse()->getContent());
        // Seul le hash est en base
        $repository = $this->em->getRepository(RefreshToken::class);
        $this->assertNull($repository->findOneBy(['tokenHash' => $token]));
        $this->assertNotNull($repository->findOneBy(['tokenHash' => hash('sha256', $token)]));
    }

    public function testLeRefreshRenvoieUnJwtValideEtLUtilisateurEtFaitTournerLeCookie(): void
    {
        $user = $this->createUser(['ROLE_USER', 'ROLE_MODERATOR']);
        $old = $this->login($user);

        $this->refresh($old);

        $this->assertResponseIsSuccessful();
        $json = $this->json();
        $this->assertSame(['id' => $user->getId(), 'pseudonym' => 'Testeur', 'email' => $user->getEmail(), 'roles' => ['ROLE_USER', 'ROLE_MODERATOR'], 'emailVerified' => true, 'pendingEmail' => null], $json['data']);
        $this->assertNotSame($old, $this->refreshCookie()->getValue());
        $this->assertTrue($this->client->getResponse()->headers->hasCacheControlDirective('no-store'));

        $this->client->request('GET', '/api/secure/me', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $json['token']]);
        $this->assertResponseIsSuccessful();
    }

    public function testSansCookieOuAvecUnCookieInconnuLeRefreshEstRefuse(): void
    {
        $this->refresh(null);
        $this->assertResponseStatusCodeSame(401);

        $this->refresh(str_repeat('a', 64));
        $this->assertResponseStatusCodeSame(401);
        $this->assertSame('', $this->refreshCookie()->getValue(), 'le cookie périmé est effacé');
    }

    public function testUnJwtInvalideJointParErreurNeBloquePasLeRefresh(): void
    {
        $token = $this->login($this->createUser());

        $this->refresh($token, ['HTTP_ORIGIN' => self::ORIGIN, 'HTTP_AUTHORIZATION' => 'Bearer jeton.invalide.ou-expire']);

        $this->assertResponseIsSuccessful();
    }

    public function testRejouerUnJetonDejaUtiliseRevoqueToutelaFamille(): void
    {
        $first = $this->login($this->createUser());

        $this->refresh($first);
        $this->assertResponseIsSuccessful();
        $second = $this->refreshCookie()->getValue();

        // Le premier jeton a été utilisé il y a plus que le délai de grâce
        $this->em->createQuery('UPDATE ' . RefreshToken::class . ' t SET t.usedAt = :past WHERE t.tokenHash = :hash')
            ->setParameter('past', new \DateTimeImmutable('-1 minute'))
            ->setParameter('hash', hash('sha256', $first))
            ->execute();

        $this->refresh($first);
        $this->assertResponseStatusCodeSame(401);

        // Le jeton légitime de la même famille est révoqué aussi
        $this->refresh($second);
        $this->assertResponseStatusCodeSame(401);
    }

    public function testUnRejeuDansLeDelaiDeGraceEstRefuseSansRevoquerLaSession(): void
    {
        $first = $this->login($this->createUser());

        $this->refresh($first);
        $second = $this->refreshCookie()->getValue();

        // Course entre deux onglets : même jeton rejoué quelques instants plus tard
        $this->refresh($first);
        $this->assertResponseStatusCodeSame(401);

        $this->refresh($second);
        $this->assertResponseIsSuccessful();
    }

    public function testUnJetonExpireEstRefuse(): void
    {
        $token = $this->login($this->createUser());
        $this->em->createQuery('UPDATE ' . RefreshToken::class . ' t SET t.expiresAt = :past')
            ->setParameter('past', new \DateTimeImmutable('-1 second'))
            ->execute();

        $this->refresh($token);

        $this->assertResponseStatusCodeSame(401);
    }

    public function testLeChangementDeMotDePasseFermeLesAutresSessionsEtRenvoieUnNouveauCookie(): void
    {
        $user = $this->createUser();
        $other = $this->login($user);

        $this->client->request('PUT', '/api/secure/update/user/' . $user->getId() . '/password', server: $this->authHeaders($user) + ['CONTENT_TYPE' => 'application/json'], content: json_encode(['currentPassword' => 'secret123', 'newPassword' => 'NouveauMdp!2026']));
        $this->assertResponseIsSuccessful();
        $fresh = $this->refreshCookie()->getValue();

        $this->refresh($other);
        $this->assertResponseStatusCodeSame(401);

        $this->refresh($fresh);
        $this->assertResponseIsSuccessful();
    }

    public function testUnJetonEmisAvantUnChangementDIdentifiantsEstRefuse(): void
    {
        $user = $this->createUser();
        $token = $this->login($user);

        $this->em->find(User::class, $user->getId())->setCredentialsChangedAt(new \DateTimeImmutable());
        $this->em->flush();

        $this->refresh($token);

        $this->assertResponseStatusCodeSame(401);
    }

    public function testLeLogoutRevoqueLaSessionEtEffaceLeCookie(): void
    {
        $token = $this->login($this->createUser());

        $this->withCookie($token);
        $this->client->request('POST', '/api/token/logout', server: ['HTTP_ORIGIN' => self::ORIGIN]);
        $this->assertResponseStatusCodeSame(204);
        $this->assertSame('', $this->refreshCookie()->getValue());

        $this->refresh($token);
        $this->assertResponseStatusCodeSame(401);
    }

    public function testLeLogoutSansCookieEstIdempotent(): void
    {
        $this->client->request('POST', '/api/token/logout', server: ['HTTP_ORIGIN' => self::ORIGIN]);
        $this->assertResponseStatusCodeSame(204);
    }

    public function testUneOrigineEtrangereEstRefusee(): void
    {
        $token = $this->login($this->createUser());

        $this->refresh($token, ['HTTP_ORIGIN' => 'https://site-malveillant.example']);
        $this->assertResponseStatusCodeSame(403);

        $this->withCookie($token);
        $this->client->request('POST', '/api/token/logout', server: ['HTTP_ORIGIN' => 'https://site-malveillant.example']);
        $this->assertResponseStatusCodeSame(403);

        // Le jeton n'a été ni consommé ni révoqué
        $this->refresh($token);
        $this->assertResponseIsSuccessful();
    }

    public function testLeRefreshEstLimiteParIp(): void
    {
        for ($i = 0; $i < 30; ++$i) {
            $this->refresh(null);
        }
        $this->refresh(null);

        $this->assertResponseStatusCodeSame(429);
        $this->assertNotEmpty($this->client->getResponse()->headers->get('Retry-After'));
    }

    public function testLesJetonsSontSupprimesAvecLeCompte(): void
    {
        $user = $this->createUser();
        $this->login($user);
        $this->deleteAccount($user);

        $this->assertSame(0, $this->em->getRepository(RefreshToken::class)->count([]));
    }

    public function testLaPurgeSupprimeLesJetonsExpires(): void
    {
        $user = $this->createUser();
        $this->login($user);
        $this->em->createQuery('UPDATE ' . RefreshToken::class . ' t SET t.expiresAt = :past')
            ->setParameter('past', new \DateTimeImmutable('-1 day'))
            ->execute();
        $this->login($user);

        $repository = static::getContainer()->get(RefreshTokenRepository::class);
        $this->assertSame(1, $repository->deleteExpiredBefore(new \DateTimeImmutable()));
        $this->assertSame(1, $repository->count([]));
    }
}
