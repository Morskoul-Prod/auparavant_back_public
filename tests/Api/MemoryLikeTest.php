<?php

namespace App\Tests\Api;

use App\Entity\Memory;
use App\Entity\MemoryLike;
use App\Entity\Notification;
use App\Entity\User;
use App\Enum\NotificationType;
use App\Tests\Support\ApiTestCase;

class MemoryLikeTest extends ApiTestCase
{
    private function toggle(Memory|int $memory, ?User $user): void
    {
        $id = $memory instanceof Memory ? $memory->getId() : $memory;
        $this->client->request('POST', '/api/secure/memory/' . $id . '/like', server: $user ? $this->authHeaders($user) : []);
    }

    private function likers(Memory|int $memory, ?User $viewer): array
    {
        $id = $memory instanceof Memory ? $memory->getId() : $memory;
        $this->client->request('GET', '/api/secure/memory/' . $id . '/likes', server: $viewer ? $this->authHeaders($viewer) : []);

        return $this->json();
    }

    /** @return array<int, array<string, mixed>> */
    private function liked(User $user): array
    {
        $this->client->request('GET', '/api/secure/notifications/comments', server: $this->authHeaders($user));

        return array_values(array_filter($this->json(), fn ($n) => $n['type'] === 'memory_liked'));
    }

    public function testBasculeEtUnicite(): void
    {
        [$author, $a, $b] = [$this->createUser(), $this->createUser(), $this->createUser()];
        $memory = $this->createMemory($author);

        $this->toggle($memory, $a);
        $this->assertResponseStatusCodeSame(200);
        $this->assertSame(['liked' => true, 'likesCount' => 1], $this->json());
        $this->toggle($memory, $b);
        $this->assertSame(['liked' => true, 'likesCount' => 2], $this->json());
        $this->toggle($memory, $a);
        $this->assertSame(['liked' => false, 'likesCount' => 1], $this->json());
        $this->toggle($memory, $a);
        $this->assertSame(['liked' => true, 'likesCount' => 2], $this->json());
        $this->assertSame(2, $this->em->getRepository(MemoryLike::class)->count([]));
    }

    public function testAnonymeEtSouvenirInexistant(): void
    {
        $user = $this->createUser();
        $memory = $this->createMemory($this->createUser());

        $this->toggle($memory, null);
        $this->assertResponseStatusCodeSame(401);
        $this->likers($memory->getId(), null);
        $this->assertResponseStatusCodeSame(401);
        $this->toggle(999999, $user);
        $this->assertResponseStatusCodeSame(404);
        $this->likers(999999, $user);
        $this->assertResponseStatusCodeSame(404);
    }

    public function testLikedByMeEtLikesCountDansLeSouvenir(): void
    {
        [$a, $b] = [$this->createUser(), $this->createUser()];
        $memory = $this->createMemory($this->createUser());
        $this->toggle($memory, $a);
        $this->toggle($memory, $b);
        $id = $memory->getId();

        $this->client->request('GET', '/api/memory/' . $id, server: $this->authHeaders($a));
        $this->assertSame([2, true], [$this->json()['likesCount'], $this->json()['likedByMe']]);
        $this->client->request('GET', '/api/memory/' . $id, server: $this->authHeaders($this->createUser()));
        $this->assertSame([2, false], [$this->json()['likesCount'], $this->json()['likedByMe']]);
        $this->client->request('GET', '/api/memory/' . $id);
        $this->assertSame([2, false], [$this->json()['likesCount'], $this->json()['likedByMe']]);
    }

    public function testListeDesLikers(): void
    {
        $users = [];
        $memory = $this->createMemory($this->createUser());
        for ($i = 0; $i < 12; $i++) {
            $users[] = $u = $this->createUser();
            $this->toggle($memory, $u);
        }

        $data = $this->likers($memory, $users[0]);
        $this->assertSame(['total', 'likers'], array_keys($data));
        $this->assertSame(12, $data['total']);
        $this->assertCount(10, $data['likers']);
        $this->assertSame([$users[11]->getId(), $users[10]->getId()], array_column(array_slice($data['likers'], 0, 2), 'id'));
        $this->assertSame(['id', 'displayName'], array_keys($data['likers'][0]));
        $this->assertStringNotContainsString('@', json_encode($data));

        $this->assertSame(['total' => 0, 'likers' => []], $this->likers($this->createMemory($this->createUser()), $users[0]));

        $this->toggle($memory, $users[11]); // unlike : disparaît de la liste
        $data = $this->likers($memory, $users[0]);
        $this->assertSame(11, $data['total']);
        $this->assertNotContains($users[11]->getId(), array_column($data['likers'], 'id'));
    }

    public function testNotificationRegroupeeEtRecalculee(): void
    {
        [$author, $a, $b] = [$this->createUser(), $this->createUser(), $this->createUser()];
        $memory = $this->createMemory($author);

        $this->toggle($memory, $a);
        $n = $this->liked($author);
        $this->assertCount(1, $n);
        $this->assertSame($memory->getId(), $n[0]['memoryId']);
        $this->assertSame($memory->getTitle(), $n[0]['memoryTitle']);
        $this->assertNull($n[0]['commentId']);
        $this->assertSame([1, $a->getDisplayName(), null, null], [$n[0]['likesCount'], $n[0]['actorDisplayName'], $n[0]['reason'], $n[0]['message']]);

        $this->toggle($memory, $b);
        $n = $this->liked($author);
        $this->assertCount(1, $n);
        $this->assertSame([2, $b->getDisplayName()], [$n[0]['likesCount'], $n[0]['actorDisplayName']]);

        $this->toggle($memory, $b); // unlike : reste A
        $n = $this->liked($author);
        $this->assertSame([1, $a->getDisplayName()], [$n[0]['likesCount'], $n[0]['actorDisplayName']]);

        $this->toggle($memory, $a); // plus aucun liker
        $this->assertCount(0, $this->liked($author));
        $this->toggle($memory, $a);
        $this->toggle($memory, $a);
        $this->toggle($memory, $a);
        $this->assertSame(1, $this->liked($author)[0]['likesCount']);
        $this->assertSame(1, $this->em->getRepository(Notification::class)->count(['type' => NotificationType::MEMORY_LIKED]));
    }

    public function testPasDeNotificationPourSonPropreLike(): void
    {
        $author = $this->createUser();
        $memory = $this->createMemory($author);
        $this->toggle($memory, $author);
        $this->assertCount(0, $this->liked($author));
    }

    public function testApresVueUneNouvelleNotification(): void
    {
        [$author, $a, $b] = [$this->createUser(), $this->createUser(), $this->createUser()];
        $memory = $this->createMemory($author);
        $this->toggle($memory, $a);
        $id = $this->liked($author)[0]['id'];
        $this->client->request('POST', '/api/secure/notifications/comments/' . $id . '/seen', server: $this->authHeaders($author));
        $this->assertCount(0, $this->liked($author));

        $this->toggle($memory, $b);
        $n = $this->liked($author);
        $this->assertCount(1, $n);
        $this->assertNotSame($id, $n[0]['id']);
        $this->assertSame(1, $n[0]['likesCount']);
    }

    public function testSuppressionDuCompteDUnLiker(): void
    {
        [$author, $a, $b] = [$this->createUser(), $this->createUser(), $this->createUser()];
        $memory = $this->createMemory($author);
        $this->toggle($memory, $a);
        $this->toggle($memory, $b);

        $this->deleteAccount($a);
        $n = $this->liked($author);
        $this->assertSame([1, $b->getDisplayName()], [$n[0]['likesCount'], $n[0]['actorDisplayName']]);
        $this->assertSame(1, $this->likers($memory, $b)['total']);

        $this->deleteAccount($b);
        $this->assertCount(0, $this->liked($author));
    }

    public function testSuppressionDuSouvenirNettoieLikesEtNotifications(): void
    {
        [$author, $a] = [$this->createUser(), $this->createUser()];
        $memory = $this->createMemory($author);
        $this->toggle($memory, $a);
        $this->assertSame(1, $this->em->getRepository(Notification::class)->count(['type' => NotificationType::MEMORY_LIKED]));

        $this->client->request('DELETE', '/api/secure/delete/memory/' . $memory->getId(), server: $this->authHeaders($author));
        $this->assertResponseIsSuccessful();
        $this->em->clear();
        $this->assertSame(0, $this->em->getRepository(MemoryLike::class)->count([]));
        $this->assertSame(0, $this->em->getRepository(Notification::class)->count(['type' => NotificationType::MEMORY_LIKED]));
    }

    /** Autre souvenir au même lieu et à la même localisation que $base */
    private function siblingOf(Memory $base): Memory
    {
        $memory = (new Memory())
            ->setUser($base->getUser())->setLocation($base->getLocation())->setPlace($base->getPlace())
            ->setTitle('Autre souvenir')->setContent('Un autre souvenir')->setYear(1960);
        $memory->addPicture($this->createPicture('before2.png', 'before', 0, \App\Enum\PictureStatus::APPROVED, $base->getUser()));
        $this->em->persist($memory);
        $this->em->flush();

        return $memory;
    }

    /** Trois souvenirs d'un même lieu avec 0, 1 et 3 likes ; [souvenirs, likes attendus par id] */
    private function memoriesWithLikes(): array
    {
        $author = $this->createUser();
        $first = $this->createMemory($author);
        $second = $this->siblingOf($first);
        $third = $this->siblingOf($first);
        $likers = [$this->createUser(), $this->createUser(), $this->createUser()];
        $this->toggle($second, $likers[0]);
        foreach ($likers as $liker) {
            $this->toggle($third, $liker);
        }
        $this->em->clear();

        return [$first, [$first->getId() => 0, $second->getId() => 1, $third->getId() => 3], $author];
    }

    /** @param array<int, int> $expected */
    private function assertLikesCounts(array $items, array $expected): void
    {
        $found = [];
        foreach ($items as $item) {
            if (isset($expected[$item['id']])) {
                $this->assertArrayHasKey('likesCount', $item);
                $this->assertArrayNotHasKey('likedByMe', $item);
                $found[$item['id']] = $item['likesCount'];
            }
        }
        ksort($found);
        ksort($expected);
        $this->assertSame($expected, $found);
    }

    public function testToutesLesListesDeSouvenirsExposentLikesCount(): void
    {
        [$first, $expected, $author] = $this->memoriesWithLikes();
        $placeId = $first->getPlace()->getId();
        $locationId = $first->getLocation()->getId();

        $this->client->request('GET', '/api/memories');
        $this->assertLikesCounts($this->json(), $expected);

        $this->client->request('GET', '/api/memorieswithtwopictures');
        $this->assertLikesCounts($this->json(), $expected);

        $this->client->request('GET', '/api/memories/latest');
        $this->assertResponseIsSuccessful();
        foreach ($this->json() as $item) {
            $this->assertSame($expected[$item['id']] ?? $item['likesCount'], $item['likesCount']);
        }

        $this->client->request('GET', '/api/memories/nearby?lat=48.846198&lng=2.346105&radius=1');
        $this->assertLikesCounts($this->json()['memories'], $expected);

        $this->client->request('GET', '/api/place/' . $placeId . '/timeline');
        $this->assertLikesCounts($this->json()['timeline'], $expected);

        $this->client->request('GET', '/api/location/' . $locationId . '/timeline');
        $this->assertLikesCounts($this->json()['timeline'], $expected);

        $this->client->request('GET', '/api/places/' . $placeId . '/memories');
        $this->assertLikesCounts($this->json(), $expected);

        $this->client->request('GET', '/api/locations/' . $locationId . '/memories');
        $this->assertLikesCounts($this->json(), $expected);

        $this->client->request('GET', '/api/secure/my-contributions', server: $this->authHeaders($this->em->getRepository(User::class)->find($author->getId())));
        $this->assertLikesCounts($this->json()['memories'], $expected);
    }

    public function testUneSeuleRequeteDeComptageQuelQueSoitLeNombreDeSouvenirs(): void
    {
        [$first] = $this->memoriesWithLikes();
        $routes = ['/api/memories', '/api/memorieswithtwopictures', '/api/place/' . $first->getPlace()->getId() . '/timeline'];

        $likeQueries = function (string $uri): int {
            $this->client->enableProfiler();
            $this->client->request('GET', $uri);
            $collector = $this->client->getProfile()->getCollector('db');
            $this->assertInstanceOf(\Symfony\Bridge\Doctrine\DataCollector\DoctrineDataCollector::class, $collector);
            $queries = $collector->getQueries();
            if (!$queries) {
                $this->markTestSkipped('Le profiler Doctrine est inactif (APP_DEBUG=0) : requêtes non mesurables.');
            }

            return count(array_filter(array_merge(...array_values($queries)), fn ($q) => str_contains($q['sql'], 'memory_like')));
        };

        $before = array_map($likeQueries, $routes);
        foreach (range(1, 5) as $unused) {
            $this->siblingOf($this->em->getRepository(Memory::class)->find($first->getId()));
        }
        $after = array_map($likeQueries, $routes);

        $this->assertSame([1, 1, 1], $before);
        $this->assertSame([1, 1, 1], $after);
    }
}
