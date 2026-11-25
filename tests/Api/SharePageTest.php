<?php

namespace App\Tests\Api;

use App\Tests\Support\ApiTestCase;

class SharePageTest extends ApiTestCase
{
    public function testLApiExposeLesSlugs(): void
    {
        $memory = $this->createMemory($this->createUser());

        $this->client->request('GET', '/api/memory/' . $memory->getId());
        $this->assertResponseIsSuccessful();
        $data = $this->json();
        $this->assertSame('le-pantheon-paris', $data['slug']);
        $this->assertSame('paris', $data['location']['citySlug']);

        $this->client->request('GET', '/api/memories');
        $list = $this->json();
        $this->assertSame('le-pantheon-paris', $list[0]['slug']);
        $this->assertSame('paris', $list[0]['location']['citySlug']);
    }

    public function testPageDuSouvenirPorteLesBalisesOpenGraph(): void
    {
        $memory = $this->createMemory($this->createUser());
        $param = $memory->getId() . '-le-pantheon-paris';

        $this->client->request('GET', '/souvenirs/' . $param);

        $this->assertResponseIsSuccessful();
        $html = $this->client->getResponse()->getContent();
        $this->assertStringContainsString('<meta property="og:title" content="Le Panthéon – Paris (1950)">', $html);
        $this->assertStringContainsString('<meta property="og:url" content="http://localhost:5173/souvenirs/' . $param . '">', $html);
        $this->assertStringContainsString('<link rel="canonical" href="http://localhost:5173/souvenirs/' . $param . '">', $html);
        $this->assertStringContainsString('<meta property="og:image" content="http://localhost/uploads/memories/before.png">', $html);
        $this->assertStringContainsString('Photo de famille devant le Panthéon', $html);
    }

    public function testUnSlugPerimeEstRedirigeEnPermanence(): void
    {
        $memory = $this->createMemory($this->createUser());

        foreach ([(string) $memory->getId(), $memory->getId() . '-ancien-titre'] as $param) {
            $this->client->request('GET', '/souvenirs/' . $param);

            $this->assertResponseStatusCodeSame(301);
            $this->assertResponseRedirects('/souvenirs/' . $memory->getId() . '-le-pantheon-paris', 301);
        }
    }

    public function testSouvenirInexistant(): void
    {
        $this->client->request('GET', '/souvenirs/999999-nimporte-quoi');

        $this->assertResponseStatusCodeSame(404);
        $this->assertStringContainsString('noindex', $this->client->getResponse()->getContent());
    }

    public function testPageDUneVille(): void
    {
        $this->createMemory($this->createUser());

        $this->client->request('GET', '/lieux/paris');

        $this->assertResponseIsSuccessful();
        $html = $this->client->getResponse()->getContent();
        $this->assertStringContainsString('<meta property="og:title" content="Souvenirs à Paris – photos avant/après">', $html);
        $this->assertStringContainsString('<meta property="og:url" content="http://localhost:5173/lieux/paris">', $html);
        $this->assertStringContainsString('1 souvenir à Paris : Le Panthéon', $html);
    }

    public function testVilleSansSouvenir(): void
    {
        $this->client->request('GET', '/lieux/atlantis');

        $this->assertResponseStatusCodeSame(404);
    }
}
