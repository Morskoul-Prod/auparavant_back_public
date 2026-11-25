<?php

namespace App\Tests\Api;

use App\Entity\Comment;
use App\Entity\CommentLike;
use App\Entity\Memory;
use App\Entity\User;
use App\Enum\CommentStatus;
use App\Tests\Support\ApiTestCase;

class CommentLikeTest extends ApiTestCase
{
    private function addComment(Memory $memory, User $author, ?Comment $parent = null): Comment
    {
        $comment = (new Comment())->setMemory($memory)->setAuthor($author)->setContent('Bonjour')->setParent($parent);
        $this->em->persist($comment);
        $this->em->flush();

        return $comment;
    }

    public function testLikeExigeUnJwt(): void
    {
        $comment = $this->addComment($this->createMemory($this->createUser()), $this->createUser());
        $this->client->request('POST', '/api/secure/comments/' . $comment->getId() . '/like');
        $this->assertResponseStatusCodeSame(401);
    }

    public function testLikeEtUnlikeBasculent(): void
    {
        $author = $this->createUser();
        $a = $this->createUser();
        $b = $this->createUser();
        $comment = $this->addComment($this->createMemory($author), $author);
        $uri = '/api/secure/comments/' . $comment->getId() . '/like';
        [$ha, $hb] = [$this->authHeaders($a), $this->authHeaders($b)];

        $this->client->request('POST', $uri, server: $ha);
        $this->assertSame(['liked' => true, 'likesCount' => 1], $this->json());
        $this->client->request('POST', $uri, server: $hb);
        $this->assertSame(['liked' => true, 'likesCount' => 2], $this->json());
        $this->client->request('POST', $uri, server: $ha);
        $this->assertSame(['liked' => false, 'likesCount' => 1], $this->json());
        $this->client->request('POST', $uri, server: $ha);
        $this->assertSame(['liked' => true, 'likesCount' => 2], $this->json());
        $this->assertSame(2, $this->em->getRepository(CommentLike::class)->count([]));
    }

    public function testLikesCountEtLikedByMeDansLaListe(): void
    {
        $author = $this->createUser();
        $a = $this->createUser();
        $b = $this->createUser();
        $memory = $this->createMemory($author);
        $root = $this->addComment($memory, $author);
        $reply = $this->addComment($memory, $author, $root);
        $memoryId = $memory->getId();
        [$ha, $hb] = [$this->authHeaders($a), $this->authHeaders($b)];

        foreach ([[$root, $ha], [$root, $hb], [$reply, $ha]] as [$comment, $server]) {
            $this->client->request('POST', '/api/secure/comments/' . $comment->getId() . '/like', server: $server);
        }

        $this->client->request('GET', '/api/memory/' . $memoryId . '/comments', server: $ha);
        $data = $this->json()['comments'][0];
        $this->assertSame([2, true], [$data['likesCount'], $data['likedByMe']]);
        $this->assertSame([1, true], [$data['replies'][0]['likesCount'], $data['replies'][0]['likedByMe']]);

        $this->client->request('GET', '/api/memory/' . $memoryId . '/comments', server: $hb);
        $data = $this->json()['comments'][0];
        $this->assertSame([2, true], [$data['likesCount'], $data['likedByMe']]);
        $this->assertSame([1, false], [$data['replies'][0]['likesCount'], $data['replies'][0]['likedByMe']]);

        // Anonyme : compteur public, jamais likedByMe
        $this->client->request('GET', '/api/memory/' . $memoryId . '/comments');
        $data = $this->json()['comments'][0];
        $this->assertSame([2, false], [$data['likesCount'], $data['likedByMe']]);
    }

    public function testPasDeLikeSurUnCommentaireSupprimeOuMasque(): void
    {
        $user = $this->createUser();
        $memory = $this->createMemory($user);
        $hidden = $this->addComment($memory, $user)->setStatus(CommentStatus::HIDDEN);
        $deleted = $this->addComment($memory, $user)->setDeletedAt(new \DateTimeImmutable());
        $this->em->flush();
        $headers = $this->authHeaders($user);

        foreach ([$hidden->getId(), $deleted->getId(), 999999] as $id) {
            $this->client->request('POST', '/api/secure/comments/' . $id . '/like', server: $headers);
            $this->assertResponseStatusCodeSame(404);
        }
    }

    public function testSupprimerUnRacineAvecReponsesEffaceSesLikes(): void
    {
        $author = $this->createUser();
        $memory = $this->createMemory($author);
        $root = $this->addComment($memory, $author);
        $this->addComment($memory, $author, $root);
        $rootId = $root->getId();
        $liker = $this->authHeaders($this->createUser());
        $authorHeaders = $this->authHeaders($author);

        $this->client->request('POST', '/api/secure/comments/' . $rootId . '/like', server: $liker);
        $this->client->request('DELETE', '/api/secure/comments/' . $rootId, server: $authorHeaders);
        $this->assertResponseStatusCodeSame(204);
        $this->assertSame(0, $this->em->getRepository(CommentLike::class)->count([]));
    }

    public function testSupprimerUnUtilisateurSupprimeSesLikes(): void
    {
        $author = $this->createUser();
        $liker = $this->createUser();
        $comment = $this->addComment($this->createMemory($author), $author);
        $headers = $this->authHeaders($liker);
        $this->client->request('POST', '/api/secure/comments/' . $comment->getId() . '/like', server: $headers);
        $this->assertSame(1, $this->em->getRepository(CommentLike::class)->count([]));

        $this->em->remove($this->em->find(User::class, $liker->getId()));
        $this->em->flush();
        $this->assertSame(0, $this->em->getRepository(CommentLike::class)->count([]));
    }
}
