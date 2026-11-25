<?php

namespace App\Tests\Api;

use App\Tests\Support\ApiTestCase;

class MemoryListOrderTest extends ApiTestCase
{
    public function testLeDernierSouvenirCreeEstEnTeteDeListe(): void
    {
        $author = $this->createUser();
        $first = $this->createMemory($author);
        $second = $this->createMemory($author);
        $third = $this->createMemory($author);
        // Dates de création explicites : l'ordre ne doit pas dépendre de l'id seul
        $first->setCreatedAt(new \DateTimeImmutable('2026-01-03 10:00:00'));
        $second->setCreatedAt(new \DateTimeImmutable('2026-01-01 10:00:00'));
        $third->setCreatedAt(new \DateTimeImmutable('2026-01-02 10:00:00'));
        $this->em->flush();

        $this->client->request('GET', '/api/memories');

        $this->assertResponseIsSuccessful();
        $this->assertSame(
            [$first->getId(), $third->getId(), $second->getId()],
            array_column($this->json(), 'id')
        );
    }

    public function testALaMemeDateLePlusRecentIdPasseAvant(): void
    {
        $author = $this->createUser();
        $older = $this->createMemory($author);
        $newer = $this->createMemory($author);
        $date = new \DateTimeImmutable('2026-01-01 10:00:00');
        $older->setCreatedAt($date);
        $newer->setCreatedAt($date);
        $this->em->flush();

        $this->client->request('GET', '/api/memories');

        $this->assertSame([$newer->getId(), $older->getId()], array_column($this->json(), 'id'));
    }
}
