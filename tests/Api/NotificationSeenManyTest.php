<?php

namespace App\Tests\Api;

use App\Entity\Picture;
use App\Entity\User;
use App\Enum\PictureStatus;
use App\Tests\Support\ApiTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class NotificationSeenManyTest extends ApiTestCase
{
    private const URI = '/api/secure/notifications/seen';

    private function post(User $user, array $body): void
    {
        $this->client->request('POST', self::URI, server: $this->authHeaders($user), content: json_encode($body));
    }

    /** Commentaires de $visitor sur autant de souvenirs de $owner : une notification chacun. */
    private function notificationIds(User $owner, int $count): array
    {
        // Tout est créé avant la première requête : le noyau est réinitialisé entre deux requêtes
        $visitor = $this->createUser();
        $memories = [];
        for ($i = 0; $i < $count; ++$i) {
            $memories[] = $this->createMemory($owner);
        }
        foreach ($memories as $memory) {
            $this->client->request('POST', '/api/secure/memory/' . $memory->getId() . '/comments', server: $this->authHeaders($visitor), content: json_encode(['content' => 'Bonjour']));
            $this->assertResponseStatusCodeSame(201);
        }
        $this->client->request('GET', '/api/secure/notifications/comments', server: $this->authHeaders($owner));

        return array_column($this->json(), 'id');
    }

    private function rejectedPicture(User $uploader): Picture
    {
        $memory = $this->createMemory($this->createUser(), PictureStatus::REJECTED, $uploader);
        $picture = $memory->getPictures()->last();
        $picture->setRejectionReason('Doublon')->setModeratedAt(new \DateTimeImmutable());
        $this->em->flush();

        return $picture;
    }

    public function testExigeUnJwt(): void
    {
        $this->client->request('POST', self::URI, content: '{}');
        $this->assertResponseStatusCodeSame(401);
    }

    public function testMarqueToutesLesNotificationsEnUnSeulAppel(): void
    {
        $owner = $this->createUser();
        $ids = $this->notificationIds($owner, 3);
        $this->assertCount(3, $ids);

        $this->post($owner, ['commentIds' => $ids]);

        $this->assertResponseIsSuccessful();
        $this->assertSame(['commentsMarked' => 3, 'picturesRemoved' => 0], $this->json());
        $this->client->request('GET', '/api/secure/notifications/comments', server: $this->authHeaders($owner));
        $this->assertSame([], $this->json());
    }

    public function testNeToucheQueAuxIdentifiantsEnvoyes(): void
    {
        $owner = $this->createUser();
        $ids = $this->notificationIds($owner, 3);

        $this->post($owner, ['commentIds' => [$ids[0]]]);

        $this->assertSame(1, $this->json()['commentsMarked']);
        $this->client->request('GET', '/api/secure/notifications/comments', server: $this->authHeaders($owner));
        $this->assertCount(2, $this->json());
    }

    public function testIgnoreLesNotificationsDUnAutreUtilisateur(): void
    {
        $owner = $this->createUser();
        $other = $this->createUser();
        $ids = $this->notificationIds($owner, 2);

        $this->post($other, ['commentIds' => $ids]);

        $this->assertResponseIsSuccessful();
        $this->assertSame(0, $this->json()['commentsMarked']);
        $this->client->request('GET', '/api/secure/notifications/comments', server: $this->authHeaders($owner));
        $this->assertCount(2, $this->json());
    }

    public function testRepeterLAppelNeChangeRien(): void
    {
        $owner = $this->createUser();
        $ids = $this->notificationIds($owner, 2);

        $this->post($owner, ['commentIds' => $ids]);
        $this->post($owner, ['commentIds' => $ids]);

        $this->assertResponseIsSuccessful();
        $this->assertSame(['commentsMarked' => 0, 'picturesRemoved' => 0], $this->json());
    }

    public function testAcquitteLesRefusDePhotoSeulementPourLAuteur(): void
    {
        $author = $this->createUser();
        $other = $this->createUser();
        $picture = $this->rejectedPicture($author);
        $id = $picture->getId();

        $this->post($other, ['pictureIds' => [$id]]);
        $this->assertSame(0, $this->json()['picturesRemoved']);
        $this->assertNotNull($this->em->find(Picture::class, $id));

        $this->post($author, ['pictureIds' => [$id]]);
        $this->assertSame(1, $this->json()['picturesRemoved']);
        $this->em->clear();
        $this->assertNull($this->em->find(Picture::class, $id));
    }

    public function testNeSupprimePasUnePhotoNonRefusee(): void
    {
        $author = $this->createUser();
        $memory = $this->createMemory($this->createUser(), PictureStatus::APPROVED, $author);
        $id = $memory->getPictures()->last()->getId();

        $this->post($author, ['pictureIds' => [$id]]);

        $this->assertResponseIsSuccessful();
        $this->assertSame(0, $this->json()['picturesRemoved']);
        $this->assertNotNull($this->em->find(Picture::class, $id));
    }

    public function testMelangeCommentairesEtPhotos(): void
    {
        $owner = $this->createUser();
        $picture = $this->rejectedPicture($owner);
        $ids = $this->notificationIds($owner, 1);

        $this->post($owner, ['commentIds' => $ids, 'pictureIds' => [$picture->getId()]]);

        $this->assertSame(['commentsMarked' => 1, 'picturesRemoved' => 1], $this->json());
    }

    #[DataProvider('invalidBodies')]
    public function testRefuseUnCorpsInvalide(string $body): void
    {
        $this->client->request('POST', self::URI, server: $this->authHeaders($this->createUser()), content: $body);

        $this->assertResponseStatusCodeSame(422);
    }

    public static function invalidBodies(): iterable
    {
        yield 'pas du JSON' => ['pas du json'];
        yield 'pas un tableau' => ['{"commentIds": 5}'];
        yield 'texte à la place d\'un entier' => ['{"commentIds": ["1"]}'];
        yield 'entier négatif' => ['{"pictureIds": [-1]}'];
        yield 'trop d\'identifiants' => ['{"commentIds": [' . implode(',', range(1, 201)) . ']}'];
    }

    public function testCorpsVideEstAccepte(): void
    {
        $this->post($this->createUser(), []);

        $this->assertResponseIsSuccessful();
        $this->assertSame(['commentsMarked' => 0, 'picturesRemoved' => 0], $this->json());
    }
}
