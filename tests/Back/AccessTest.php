<?php

namespace App\Tests\Back;

use App\Entity\User;
use App\Tests\Support\ApiTestCase;

/**
 * Matrice d'accès au back-office : anonyme, utilisateur, modérateur, administrateur.
 */
class AccessTest extends ApiTestCase
{
    private const MODERATION_PAGES = ['/back', '/back/moderation/pictures/', '/back/reports/', '/back/comments/hidden/'];
    private const ADMIN_PAGES = [
        '/back/user/', '/back/user/new', '/back/memory/', '/back/memory/new', '/back/location/', '/back/place/', '/back/picture/',
        '/back/sort/user/?order=id', '/back/sort/memory/?order=id', '/back/sort/location/?order=id', '/back/sort/place/?order=id', '/back/sort/picture/?order=id',
    ];

    private function statusOf(string $uri): int
    {
        $this->client->request('GET', $uri);

        return $this->client->getResponse()->getStatusCode();
    }

    public function testAnonymeRedirigeVersLaConnexionPartout(): void
    {
        foreach ([...self::MODERATION_PAGES, ...self::ADMIN_PAGES] as $uri) {
            $this->assertSame(302, $this->statusOf($uri), $uri);
            $this->assertResponseRedirects('http://localhost/', null, $uri);
        }
    }

    public function testLesPagesPubliquesRestentAccessibles(): void
    {
        foreach (['/', '/register', '/reset-password'] as $uri) {
            $this->assertSame(200, $this->statusOf($uri), $uri);
        }
    }

    public function testUnUtilisateurSimpleEstRefuseePartout(): void
    {
        $this->client->loginUser($this->createUser(['ROLE_USER']));
        foreach ([...self::MODERATION_PAGES, ...self::ADMIN_PAGES] as $uri) {
            $this->assertSame(403, $this->statusOf($uri), $uri);
        }
    }

    public function testUnModerateurAccedeSeulementALaModerationEtAuxSignalements(): void
    {
        $this->client->loginUser($this->createUser(['ROLE_USER', 'ROLE_MODERATOR']));
        foreach (self::MODERATION_PAGES as $uri) {
            $this->assertSame(200, $this->statusOf($uri), $uri);
        }
        foreach (self::ADMIN_PAGES as $uri) {
            $this->assertSame(403, $this->statusOf($uri), $uri);
        }
    }

    public function testLeMenuDuModerateurNeProposePasLaGestion(): void
    {
        $this->client->loginUser($this->createUser(['ROLE_MODERATOR']));
        $this->client->request('GET', '/back');

        $this->assertSelectorExists('a[href="/back/reports/"]');
        $this->assertSelectorExists('a[href="/back/moderation/pictures/"]');
        $this->assertSelectorNotExists('a[href="/back/user/"]');
        $this->assertSelectorNotExists('a[href="/back/memory/"]');
        $this->assertSelectorTextContains('.header-user-role', 'Modérateur');
    }

    public function testUnAdministrateurAccedeATout(): void
    {
        $this->client->loginUser($this->createUser(['ROLE_ADMIN']));
        foreach ([...self::MODERATION_PAGES, ...self::ADMIN_PAGES] as $uri) {
            $this->assertSame(200, $this->statusOf($uri), $uri);
        }
        $this->client->request('GET', '/back');
        $this->assertSelectorExists('a[href="/back/user/"]');
    }

    public function testLesActionsDestructricesSontProtegees(): void
    {
        $victim = $this->createUser();
        $victimId = $victim->getId();

        foreach ([null, $this->createUser(['ROLE_USER']), $this->createUser(['ROLE_MODERATOR'])] as $actor) {
            if ($actor) {
                $this->client->loginUser($actor);
            }
            $this->client->request('POST', '/back/user/' . $victimId, ['_token' => 'x']);
            $this->assertContains($this->client->getResponse()->getStatusCode(), [302, 403]);
            $this->assertNotNull($this->em->getRepository(User::class)->find($victimId));
        }
    }

    public function testApiEtJwtNeSontPasAffectes(): void
    {
        $this->client->request('GET', '/api/memories');
        $this->assertResponseIsSuccessful();
    }
}
