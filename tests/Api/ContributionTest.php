<?php

namespace App\Tests\Api;

use App\Enum\PictureStatus;
use App\Tests\Support\ApiTestCase;

class ContributionTest extends ApiTestCase
{
    private function get(string $query, ?\App\Entity\User $user): array
    {
        $this->client->request('GET', '/api/secure/my-contributions' . $query, server: $user ? $this->authHeaders($user) : []);

        return $this->json();
    }

    public function testExigeUnJwt(): void
    {
        $this->client->request('GET', '/api/secure/my-contributions');
        $this->assertResponseStatusCodeSame(401);
    }

    public function testAncienneRouteAvecIdSupprimee(): void
    {
        $user = $this->createUser();
        $this->client->request('GET', '/api/secure/' . $user->getId() . '/my-contributions', server: $this->authHeaders($user));
        $this->assertResponseStatusCodeSame(404);
    }

    public function testUnUtilisateurNeVoitQueSesContributions(): void
    {
        $a = $this->createUser();
        $b = $this->createUser();
        $mine = $this->createMemory($a, PictureStatus::PENDING, $a);
        $this->createMemory($b, PictureStatus::APPROVED, $b);
        // photo proposée par B sur un souvenir de A : visible chez B uniquement
        $this->createMemory($a, PictureStatus::PENDING, $b);

        $data = $this->get('', $a);
        $this->assertResponseIsSuccessful();
        $this->assertCount(2, $data['memories']);
        $this->assertSame($a->getId(), $data['memories'][0]['user']['id']);
        $this->assertCount(1, $data['pictures']);
        $this->assertSame($mine->getId(), $data['pictures'][0]['memoryId']);
        $this->assertSame('before.png', $data['pictures'][0]['memoryMainPicture']['imageName']);
        $this->assertSame(2, $data['stats']['memories']);

        $dataB = $this->get('', $b);
        $this->assertCount(1, $dataB['memories']);
        $this->assertCount(2, $dataB['pictures']);
    }

    public function testLesTroisStatutsEtLeMotifDeRefus(): void
    {
        $user = $this->createUser();
        $author = $this->createUser();
        $this->createMemory($author, PictureStatus::PENDING, $user);
        $this->createMemory($author, PictureStatus::APPROVED, $user);
        $rejected = $this->createMemory($author, PictureStatus::REJECTED, $user)->getPictures()->last();
        $rejected->setRejectionReason('Photo floue')->setModeratedAt(new \DateTimeImmutable('2026-10-27 10:00:00'));
        $this->em->flush();

        $data = $this->get('', $user);
        $byStatus = array_column($data['pictures'], null, 'status');
        $this->assertCount(3, $byStatus);
        $this->assertSame('Photo floue', $byStatus['rejected']['rejectionReason']);
        $this->assertSame('2026-10-27T10:00:00+00:00', $byStatus['rejected']['moderatedAt']);
        $this->assertNull($byStatus['pending']['rejectionReason']);
        $this->assertNull($byStatus['approved']['rejectionReason']);
        $this->assertSame(
            ['memories' => 0, 'pictures' => 3, 'picturesPending' => 1, 'picturesApproved' => 1, 'picturesRejected' => 1, 'comments' => 0],
            $data['stats']
        );
    }

    public function testPagination(): void
    {
        $user = $this->createUser();
        for ($i = 0; $i < 3; $i++) {
            $this->createMemory($user, PictureStatus::APPROVED, $user);
        }

        $page1 = $this->get('?page=1&limit=2', $user);
        $page2 = $this->get('?page=2&limit=2', $user);

        $this->assertCount(2, $page1['memories']);
        $this->assertCount(2, $page1['pictures']);
        $this->assertCount(1, $page2['memories']);
        $this->assertCount(1, $page2['pictures']);
        $this->assertSame(3, $page1['stats']['memories']);
        $this->assertSame(3, $page1['stats']['pictures']);
        $this->assertSame(['page' => 2, 'limit' => 2], $page2['pagination']);
        $this->assertEmpty(array_intersect(array_column($page1['memories'], 'id'), array_column($page2['memories'], 'id')));
        // du plus récent au plus ancien
        $this->assertGreaterThan($page2['memories'][0]['id'], $page1['memories'][0]['id']);
    }

    public function testLimiteBorneeEtValeursInvalides(): void
    {
        $user = $this->createUser();
        $data = $this->get('?page=-4&limit=9999', $user);
        $this->assertSame(['page' => 1, 'limit' => 50], $data['pagination']);
    }

    public function testFiltreParStatut(): void
    {
        $user = $this->createUser();
        $author = $this->createUser();
        $this->createMemory($author, PictureStatus::PENDING, $user);
        $this->createMemory($author, PictureStatus::APPROVED, $user);
        $this->createMemory($author, PictureStatus::APPROVED, $user);

        $data = $this->get('?status=approved', $user);
        $this->assertCount(2, $data['pictures']);
        $this->assertSame(['approved', 'approved'], array_column($data['pictures'], 'status'));
        // les compteurs ne dépendent pas du filtre
        $this->assertSame(3, $data['stats']['pictures']);
        $this->assertSame(2, $data['stats']['picturesApproved']);

        $this->assertCount(1, $this->get('?status=pending', $user)['pictures']);
        $this->assertCount(3, $this->get('?status=', $user)['pictures']);
    }

    public function testStatutInconnuRenvoie400(): void
    {
        $user = $this->createUser();
        $this->client->request('GET', '/api/secure/my-contributions?status=nimporte', server: $this->authHeaders($user));
        $this->assertResponseStatusCodeSame(400);
    }
}
