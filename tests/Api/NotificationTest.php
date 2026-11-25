<?php

namespace App\Tests\Api;

use App\Entity\Picture;
use App\Enum\PictureStatus;
use App\Tests\Support\ApiTestCase;

class NotificationTest extends ApiTestCase
{
    /** Crée un souvenir dont la photo « after » proposée par $uploader a le statut donné. */
    private function proposition(\App\Entity\User $uploader, PictureStatus $status): Picture
    {
        $memory = $this->createMemory($this->createUser(), $status, $uploader);
        $picture = $memory->getPictures()->last();
        if ($status === PictureStatus::REJECTED) {
            $picture->setRejectionReason('Doublon')->setModeratedAt(new \DateTimeImmutable('2026-10-27 10:00:00'));
            $this->em->flush();
        }

        return $picture;
    }

    public function testLesNotificationsExigentUnJwt(): void
    {
        $this->client->request('GET', '/api/secure/notifications');
        $this->assertResponseStatusCodeSame(401);
    }

    public function testSeulLAuteurVoitSesRefusNonVus(): void
    {
        $a = $this->createUser();
        $b = $this->createUser();
        $rejected = $this->proposition($a, PictureStatus::REJECTED);
        $this->proposition($a, PictureStatus::PENDING);
        $this->proposition($a, PictureStatus::APPROVED);

        $this->client->request('GET', '/api/secure/notifications', server: $this->authHeaders($a));
        $this->assertResponseIsSuccessful();
        $data = $this->json();
        $this->assertCount(1, $data);
        $this->assertSame($rejected->getId(), $data[0]['pictureId']);
        $this->assertSame('Doublon', $data[0]['reason']);
        $this->assertSame($rejected->getImageUrl(), $data[0]['imageUrl']);
        $this->assertNotNull($data[0]['imageUrl']);
        $this->assertSame('2026-10-27T10:00:00+00:00', $data[0]['moderatedAt']);

        $this->client->request('GET', '/api/secure/notifications', server: $this->authHeaders($b));
        $this->assertSame([], $this->json());
    }

    public function testSeenEstReserveALAuteurEtSupprimeLaPhoto(): void
    {
        $a = $this->createUser();
        $b = $this->createUser();
        $rejected = $this->proposition($a, PictureStatus::REJECTED);
        $id = $rejected->getId();
        $path = $this->storeFile($rejected);
        $uri = '/api/secure/notifications/' . $id . '/seen';

        // Un autre utilisateur ne peut rien acquitter : la photo reste intacte
        $this->client->request('POST', $uri, server: $this->authHeaders($b));
        $this->assertResponseStatusCodeSame(404);
        $this->assertFileExists($path);
        $this->assertNotNull($this->em->find(Picture::class, $id));

        $this->client->request('POST', $uri, server: $this->authHeaders($a));
        $this->assertResponseIsSuccessful();
        $this->assertFileDoesNotExist($path);
        $this->em->clear();
        $this->assertNull($this->em->find(Picture::class, $id));

        $this->client->request('GET', '/api/secure/notifications', server: $this->authHeaders($a));
        $this->assertSame([], $this->json());
    }

    public function testSeenRepeteNeChangeRienEtRenvoie404(): void
    {
        $a = $this->createUser();
        $rejected = $this->proposition($a, PictureStatus::REJECTED);
        $memory = $rejected->getMemory();
        $nbPhotos = $memory->getPictures()->count();
        $uri = '/api/secure/notifications/' . $rejected->getId() . '/seen';

        $this->client->request('POST', $uri, server: $this->authHeaders($a));
        $this->assertResponseIsSuccessful();
        $this->client->request('POST', $uri, server: $this->authHeaders($a));
        $this->assertResponseStatusCodeSame(404);

        // Le souvenir et ses autres photos ne sont pas touchés
        $this->em->clear();
        $memory = $this->em->find(\App\Entity\Memory::class, $memory->getId());
        $this->assertNotNull($memory);
        $this->assertCount($nbPhotos - 1, $memory->getPictures());
    }

    public function testSeenNeSupprimePasUnePhotoNonRefusee(): void
    {
        $a = $this->createUser();
        foreach ([PictureStatus::PENDING, PictureStatus::APPROVED] as $status) {
            $picture = $this->proposition($a, $status);
            $this->client->request('POST', '/api/secure/notifications/' . $picture->getId() . '/seen', server: $this->authHeaders($a));
            $this->assertResponseStatusCodeSame(404);
            $this->assertNotNull($this->em->find(Picture::class, $picture->getId()));
        }
    }

    public function testPierreTombaleNotifieSansExposerDeFichier(): void
    {
        $a = $this->createUser();
        $rejected = $this->proposition($a, PictureStatus::REJECTED);
        $rejected->setImageName(null); // fichier supprimé au rejet « inapproprié »
        $this->em->flush();

        $this->client->request('GET', '/api/secure/notifications', server: $this->authHeaders($a));
        $this->assertSame($rejected->getId(), $this->json()[0]['pictureId']);
        $this->assertNull($this->json()[0]['imageUrl']);

        $this->client->request('GET', '/api/memory/' . $rejected->getMemory()->getId(), server: $this->authHeaders($a));
        $photo = array_values(array_filter($this->json()['pictures'], fn ($p) => $p['id'] === $rejected->getId()))[0];
        $this->assertSame('rejected', $photo['status']);
        $this->assertSame('Doublon', $photo['rejectionReason']);
        $this->assertNull($photo['imageUrl']);
        $this->assertNull($photo['imageName']);
    }

    public function testSupprimerLeSouvenirSupprimeSesNotifications(): void
    {
        $a = $this->createUser();
        $rejected = $this->proposition($a, PictureStatus::REJECTED);
        $path = $this->storeFile($rejected);
        $memory = $rejected->getMemory();

        $this->em->remove($memory);
        $this->em->flush();

        $this->assertFileDoesNotExist($path);
        $this->client->request('GET', '/api/secure/notifications', server: $this->authHeaders($a));
        $this->assertSame([], $this->json());
    }

    public function testUnePhotoRefuseeNeFuitSurAucuneRoutePublique(): void
    {
        $a = $this->createUser();
        $rejected = $this->proposition($a, PictureStatus::REJECTED);
        foreach (['/api/memories', '/api/memories/latest', '/api/pictures', '/api/picture/' . $rejected->getId()] as $uri) {
            $this->client->request('GET', $uri, server: $this->authHeaders($a));
            $this->assertStringNotContainsString('rejectionReason', $this->client->getResponse()->getContent(), $uri);
            $this->assertStringNotContainsString('"status":"rejected"', $this->client->getResponse()->getContent(), $uri);
        }
    }

    public function testLeRefusEstExposeDansLeSouvenirSeulementPourSonAuteur(): void
    {
        $a = $this->createUser();
        $b = $this->createUser();
        $rejected = $this->proposition($a, PictureStatus::REJECTED);
        $memoryId = $rejected->getMemory()->getId();

        $ids = fn (array $server) => (function () use ($memoryId, $server) {
            $this->client->request('GET', '/api/memory/' . $memoryId, server: $server);

            return array_column($this->json()['pictures'], 'rejectionReason', 'id');
        })();

        $this->assertSame('Doublon', $ids($this->authHeaders($a))[$rejected->getId()]);
        $this->assertArrayNotHasKey($rejected->getId(), $ids($this->authHeaders($b)));
        $this->assertArrayNotHasKey($rejected->getId(), $ids([]));
    }
}
