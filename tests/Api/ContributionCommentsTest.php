<?php

namespace App\Tests\Api;

use App\Entity\Comment;
use App\Entity\CommentLike;
use App\Entity\Memory;
use App\Entity\User;
use App\Enum\CommentStatus;
use App\Tests\Support\ApiTestCase;

class ContributionCommentsTest extends ApiTestCase
{
    private function addComment(Memory $memory, ?User $author, string $content, ?Comment $parent = null, string $date = 'now'): Comment
    {
        $comment = (new Comment())->setMemory($memory)->setAuthor($author)->setContent($content)->setParent($parent)
            ->setCreatedAt(new \DateTimeImmutable($date));
        $this->em->persist($comment);
        $this->em->flush();

        return $comment;
    }

    private function get(string $query, User $user): array
    {
        $this->client->request('GET', '/api/secure/my-contributions' . $query, server: $this->authHeaders($user));
        $this->assertResponseIsSuccessful();

        return $this->json();
    }

    public function testCommentairesEtReponsesDeLUtilisateurSeulement(): void
    {
        $me = $this->createUser();
        $other = $this->createUser();
        $memory = $this->createMemory($other);
        $root = $this->addComment($memory, $me, 'Mon racine', null, '2026-01-01');
        $reply = $this->addComment($memory, $me, 'Ma réponse', $root, '2026-01-02');
        $this->addComment($memory, $other, 'Pas à moi', $root, '2026-01-03');
        $this->addComment($memory, $me, 'Masqué', null, '2026-01-04')->setStatus(CommentStatus::HIDDEN);
        $this->em->persist((new CommentLike())->setComment($root)->setUser($other));
        $this->em->persist((new CommentLike())->setComment($root)->setUser($me));
        $this->em->flush();
        [$rootId, $replyId, $memoryId] = [$root->getId(), $reply->getId(), $memory->getId()];

        $data = $this->get('', $me);

        $this->assertSame(2, $data['stats']['comments']);
        $this->assertSame([$replyId, $rootId], array_column($data['comments'], 'id'));
        $this->assertSame([
            'id' => $replyId, 'content' => 'Ma réponse', 'createdAt' => '2026-01-02T00:00:00+00:00',
            'memoryId' => $memoryId, 'memoryTitle' => 'Le Panthéon', 'parentId' => $rootId, 'likesCount' => 0,
        ], $data['comments'][0]);
        $this->assertNull($data['comments'][1]['parentId']);
        $this->assertSame(2, $data['comments'][1]['likesCount']);
    }

    public function testPaginationDesCommentaires(): void
    {
        $me = $this->createUser();
        $memory = $this->createMemory($this->createUser());
        foreach ([1, 2, 3] as $i) {
            $this->addComment($memory, $me, 'c' . $i, null, '2026-01-0' . $i);
        }

        $page1 = $this->get('?limit=2', $me);
        $this->assertSame(['c3', 'c2'], array_column($page1['comments'], 'content'));
        $this->assertSame(['page' => 1, 'limit' => 2], $page1['pagination']);
        $this->assertSame(3, $page1['stats']['comments']);
        $this->assertSame(['c1'], array_column($this->get('?limit=2&page=2', $me)['comments'], 'content'));
    }

    public function testLesCleExistantesSontInchangees(): void
    {
        $me = $this->createUser();
        $data = $this->get('', $me);

        $this->assertSame(['stats', 'pagination', 'memories', 'pictures', 'comments'], array_keys($data));
        $this->assertSame(
            ['memories', 'pictures', 'picturesPending', 'picturesApproved', 'picturesRejected', 'comments'],
            array_keys($data['stats'])
        );
        $this->assertSame([], $data['comments']);
        $this->assertSame(0, $data['stats']['comments']);
    }

    public function testUnCommentaireSupprimeNEstPlusCompte(): void
    {
        $me = $this->createUser();
        $memory = $this->createMemory($this->createUser());
        $root = $this->addComment($memory, $me, 'Racine');
        $this->addComment($memory, $this->createUser(), 'Réponse', $root);
        $headers = $this->authHeaders($me);

        $this->client->request('DELETE', '/api/secure/comments/' . $root->getId(), server: $headers);
        $this->assertResponseStatusCodeSame(204);

        $data = $this->get('', $me);
        $this->assertSame(0, $data['stats']['comments']);
        $this->assertSame([], $data['comments']);
    }
}
