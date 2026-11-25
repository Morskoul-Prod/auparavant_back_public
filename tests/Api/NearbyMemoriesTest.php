<?php

namespace App\Tests\Api;

use App\Tests\Support\ApiTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * GET /api/memories/nearby : souvenirs autour d'une position, du plus proche au plus éloigné.
 */
class NearbyMemoriesTest extends ApiTestCase
{
    // createMemory() place le souvenir au Panthéon (48.846198, 2.346105)
    private const PANTHEON = 'lat=48.846198&lng=2.346105';

    public function testRenvoieLeSouvenirAvecSaDistanceEtLeFormatDesListes(): void
    {
        $memory = $this->createMemory($this->createUser());

        // ~110 m au nord du Panthéon
        $this->client->request('GET', '/api/memories/nearby?lat=48.8472&lng=2.346105&radius=1');

        $this->assertResponseIsSuccessful();
        $body = $this->json();
        $this->assertSame(1, $body['count']);
        $item = $body['memories'][0];
        $this->assertSame($memory->getId(), $item['id']);
        $this->assertEqualsWithDelta(110, $item['distance'], 15);
        $this->assertArrayHasKey('pictures', $item);
        $this->assertArrayHasKey('location', $item);
        $this->assertArrayHasKey('likesCount', $item);
    }

    public function testUnPointConfonduAvecLeSouvenirEstTrouve(): void
    {
        $this->createMemory($this->createUser());

        $this->client->request('GET', '/api/memories/nearby?' . self::PANTHEON . '&radius=0.1');

        $this->assertSame(1, $this->json()['count']);
        $this->assertLessThan(2, $this->json()['memories'][0]['distance']);
    }

    public function testExclutLesSouvenirsHorsDuRayon(): void
    {
        $this->createMemory($this->createUser());

        // Notre-Dame, ~1,2 km du Panthéon
        $this->client->request('GET', '/api/memories/nearby?lat=48.8530&lng=2.3499&radius=0.5');
        $this->assertSame(0, $this->json()['count']);

        $this->client->request('GET', '/api/memories/nearby?lat=48.8530&lng=2.3499&radius=2');
        $this->assertSame(1, $this->json()['count']);
    }

    public function testTriDuPlusProcheAuPlusEloigne(): void
    {
        $author = $this->createUser();
        $far = $this->createMemory($author);
        $near = $this->createMemory($author);
        $far->getLocation()->setLatitude('48.8500');
        $this->em->flush();

        $this->client->request('GET', '/api/memories/nearby?' . self::PANTHEON . '&radius=2');

        $ids = array_column($this->json()['memories'], 'id');
        $this->assertSame([$near->getId(), $far->getId()], $ids);
    }

    public function testLaLimiteEstRespectee(): void
    {
        $author = $this->createUser();
        $this->createMemory($author);
        $this->createMemory($author);

        $this->client->request('GET', '/api/memories/nearby?' . self::PANTHEON . '&limit=1');

        $this->assertSame(1, $this->json()['count']);
    }

    public function testLeRayonEstPlafonne(): void
    {
        $this->client->request('GET', '/api/memories/nearby?' . self::PANTHEON . '&radius=100000');

        $this->assertResponseIsSuccessful();
        $this->assertSame(50.0, (float) $this->json()['radius_km']);
    }

    #[DataProvider('coordonneesInvalides')]
    public function testCoordonneesInvalidesRenvoient400(string $query): void
    {
        $this->client->request('GET', '/api/memories/nearby?' . $query);

        $this->assertResponseStatusCodeSame(400);
    }

    public static function coordonneesInvalides(): iterable
    {
        yield 'absentes' => [''];
        yield 'longitude manquante' => ['lat=48.8'];
        yield 'non numériques' => ['lat=abc&lng=2.3'];
        yield 'latitude hors limites' => ['lat=91&lng=2.3'];
        yield 'longitude hors limites' => ['lat=48.8&lng=181'];
    }

    public function testLEquatorEtLeMeridienDeGreenwichSontAcceptes(): void
    {
        $this->client->request('GET', '/api/memories/nearby?lat=0&lng=0');

        $this->assertResponseIsSuccessful();
        $this->assertSame(0, $this->json()['count']);
    }
}
