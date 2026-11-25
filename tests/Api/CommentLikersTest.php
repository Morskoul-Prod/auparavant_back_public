<?php

namespace App\Tests\Api;

use App\Entity\Comment;
use App\Entity\User;
use App\Service\CommentManager;
use App\Tests\Support\ApiTestCase;

class CommentLikersTest extends ApiTestCase
{
    private function addComment(User $author): Comment
    {
        $comment = (new Comment())->setMemory($this->createMemory($author))->setAuthor($author)->setContent('Un souvenir');
        $this->em->persist($comment);
        $this->em->flush();

        return $comment;
    }

    private function like(Comment $comment, User $user): void
    {
        $this->client->request('POST', '/api/secure/comments/' . $comment->getId() . '/like', server: $this->authHeaders($user));
        $this->assertResponseIsSuccessful();
    }

    private function likers(Comment|int $comment, User $viewer): array
    {
        $id = $comment instanceof Comment ? $comment->getId() : $comment;
        $this->client->request('GET', '/api/secure/comments/' . $id . '/likes', server: $this->authHeaders($viewer));

        return $this->json();
    }

    public function testTroisLikesDuPlusRecentAuPlusAncien(): void
    {
        $author = $this->createUser();
        [$a, $b, $c] = [$this->createUser(), $this->createUser(), $this->createUser()];
        $comment = $this->addComment($author);
        foreach ([$a, $b, $c] as $u) {
            $this->like($comment, $u);
        }

        $data = $this->likers($comment, $a);
        $this->assertResponseStatusCodeSame(200);
        $this->assertSame(3, $data['total']);
        $this->assertSame(
            [['id' => $c->getId(), 'displayName' => $c->getDisplayName()], ['id' => $b->getId(), 'displayName' => $b->getDisplayName()], ['id' => $a->getId(), 'displayName' => $a->getDisplayName()]],
            $data['likers']
        );
        $this->assertSame(['total', 'likers'], array_keys($data));
        $this->assertStringNotContainsString('@', json_encode($data));
        $this->assertStringNotContainsString('ROLE_', json_encode($data));
    }

    public function testPlafondDeDixLikers(): void
    {
        $comment = $this->addComment($this->createUser());
        $users = [];
        for ($i = 0; $i < 12; $i++) {
            $users[] = $u = $this->createUser();
            $this->like($comment, $u);
        }

        $data = $this->likers($comment, $users[0]);
        $this->assertSame(12, $data['total']);
        $this->assertCount(10, $data['likers']);
        $this->assertSame($users[11]->getId(), $data['likers'][0]['id']);
    }

    public function testSansLike(): void
    {
        $viewer = $this->createUser();
        $this->assertSame(['total' => 0, 'likers' => []], $this->likers($this->addComment($this->createUser()), $viewer));
    }

    public function testAnonymeEtInexistantEtMasque(): void
    {
        $viewer = $this->createUser();
        $comment = $this->addComment($this->createUser());

        $this->client->request('GET', '/api/secure/comments/' . $comment->getId() . '/likes');
        $this->assertResponseStatusCodeSame(401);

        $this->likers(999999, $viewer);
        $this->assertResponseStatusCodeSame(404);

        $this->like($comment, $viewer);
        self::getContainer()->get(CommentManager::class)->hide($this->em->getRepository(Comment::class)->find($comment->getId()));
        $this->em->flush();
        $this->likers($comment, $viewer);
        $this->assertResponseStatusCodeSame(404);
    }

    public function testCompteSupprimeEtUnlike(): void
    {
        [$a, $b, $c] = [$this->createUser(), $this->createUser(), $this->createUser()];
        $comment = $this->addComment($this->createUser());
        foreach ([$a, $b, $c] as $u) {
            $this->like($comment, $u);
        }

        $this->deleteAccount($a);
        $data = $this->likers($comment, $b);
        $this->assertSame(2, $data['total']);
        $this->assertSame([$c->getId(), $b->getId()], array_column($data['likers'], 'id'));

        $this->like($comment, $b); // unlike
        $data = $this->likers($comment, $c);
        $this->assertSame(1, $data['total']);
        $this->assertSame([$c->getId()], array_column($data['likers'], 'id'));
    }

    public function testTotalIdentiqueALikesCountDeLaListe(): void
    {
        $author = $this->createUser();
        $comment = $this->addComment($author);
        $memoryId = $comment->getMemory()->getId();
        foreach ([$this->createUser(), $this->createUser()] as $u) {
            $this->like($comment, $u);
        }
        $total = $this->likers($comment, $author)['total'];
        $this->client->request('GET', '/api/memory/' . $memoryId . '/comments');
        $this->assertSame($total, $this->json()['comments'][0]['likesCount']);
    }
}
