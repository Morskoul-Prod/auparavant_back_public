<?php

namespace App\Tests\Api;

use App\Entity\User;
use App\Tests\Support\ApiTestCase;

/**
 * L'ancienne route POST /hidden/api/secure/create/user désérialisait tout le JSON dans User
 * (rôles et mot de passe en clair compris), sans authentification (hors du pare-feu /api).
 * Elle est supprimée : un compte se crée par /api/register ou par le back-office.
 */
class CreateUserRouteTest extends ApiTestCase
{
    private const PAYLOAD = ['email' => 'pirate@example.fr', 'password' => 'clair', 'roles' => ['ROLE_ADMIN'], 'pseudonym' => 'Pirate'];

    private function post(string $uri, ?User $as): void
    {
        $this->client->request('POST', $uri, server: ($as ? $this->authHeaders($as) : []) + ['CONTENT_TYPE' => 'application/json'], content: json_encode(self::PAYLOAD));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function routes(): iterable
    {
        yield 'ancienne route cachée' => ['/hidden/api/secure/create/user', false];
        yield 'ancienne route cachée connecté' => ['/hidden/api/secure/create/user', true];
        yield 'route sous /api/secure' => ['/api/secure/create/user', true];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('routes')]
    public function testAucuneRouteNeCreeUnCompteBrut(string $uri, bool $connected): void
    {
        $this->post($uri, $connected ? $this->createUser() : null);

        $this->assertContains($this->client->getResponse()->getStatusCode(), [401, 403, 404, 405]);
        $this->assertNull($this->em->getRepository(User::class)->findOneBy(['email' => self::PAYLOAD['email']]));
    }
}
