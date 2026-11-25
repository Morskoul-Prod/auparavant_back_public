<?php

namespace App\Tests\Api;

use App\Enum\PictureStatus;
use App\Tests\Support\ApiTestCase;

class RandomMemoryTest extends ApiTestCase
{
    public function testRenvoie404SansSouvenirAvecPhotoAvantEtApres(): void
    {
        // Souvenir sans photo "after"
        $this->createMemory($this->createUser());

        $this->client->request('GET', '/api/memories/random');

        $this->assertResponseStatusCodeSame(404);
    }

    public function testIgnoreLesPhotosActuellesNonApprouvees(): void
    {
        $author = $this->createUser();
        $this->createMemory($author, PictureStatus::PENDING);
        $this->createMemory($author, PictureStatus::REJECTED);

        $this->client->request('GET', '/api/memories/random');

        $this->assertResponseStatusCodeSame(404);
    }

    public function testRenvoieLesPhotosAvantEtApresSansAuthentification(): void
    {
        $author = $this->createUser();
        $memory = $this->createMemory($author, PictureStatus::APPROVED);

        $this->client->request('GET', '/api/memories/random');

        $this->assertResponseIsSuccessful();
        $data = $this->json();
        $this->assertSame($memory->getId(), $data['id']);
        $this->assertSame('Le Panthéon', $data['title']);
        $this->assertSame(1950, $data['year']);
        $this->assertSame('/uploads/memories/before.png', $data['before']['imageUrl']);
        $this->assertSame('/uploads/memories/after.png', $data['after']['imageUrl']);
    }
}
