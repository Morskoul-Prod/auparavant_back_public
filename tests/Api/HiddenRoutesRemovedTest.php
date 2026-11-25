<?php

namespace App\Tests\Api;

use App\Entity\Location;
use App\Entity\Place;
use App\Tests\Support\ApiTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Les anciennes routes `hidden/api/secure/{create,update,delete}/{place,location}` étaient hors du
 * pare-feu /api (chemin sans « /api » en tête) : anonymes, elles permettaient de supprimer des lieux
 * et, en cascade, des souvenirs. Elles sont supprimées ; l'écriture passe par les routes de souvenirs
 * ou par le back-office.
 */
class HiddenRoutesRemovedTest extends ApiTestCase
{
    /** @return iterable<string, array{string, string, bool}> */
    public static function routes(): iterable
    {
        foreach (['place', 'location'] as $e) {
            foreach (['anonyme' => false, 'connecté' => true] as $label => $connected) {
                yield "create $e $label" => ['POST', "/hidden/api/secure/create/$e", $connected];
                yield "update $e $label" => ['PUT', "/hidden/api/secure/update/$e/1", $connected];
                yield "delete $e $label" => ['DELETE', "/hidden/api/secure/delete/$e/1", $connected];
            }
            yield "create $e sous /api/secure" => ['POST', "/api/secure/create/$e", true];
        }
    }

    #[DataProvider('routes')]
    public function testRouteSupprimee(string $method, string $uri, bool $connected): void
    {
        $server = ($connected ? $this->authHeaders($this->createUser(['ROLE_ADMIN'])) : []) + ['CONTENT_TYPE' => 'application/json'];
        $before = [$this->em->getRepository(Place::class)->count([]), $this->em->getRepository(Location::class)->count([])];

        $this->client->request($method, $uri, server: $server, content: '{"name":"x"}');

        $this->assertContains($this->client->getResponse()->getStatusCode(), [401, 403, 404, 405]);
        $this->assertSame($before, [$this->em->getRepository(Place::class)->count([]), $this->em->getRepository(Location::class)->count([])]);
    }
}
