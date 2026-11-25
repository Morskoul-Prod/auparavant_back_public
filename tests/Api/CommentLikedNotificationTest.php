<?php

namespace App\Tests\Api;

use App\Entity\Comment;
use App\Entity\Memory;
use App\Entity\Notification;
use App\Entity\User;
use App\Enum\NotificationType;
use App\Service\CommentManager;
use App\Tests\Support\ApiTestCase;

class CommentLikedNotificationTest extends ApiTestCase
{
    private function addComment(Memory $memory, User $author, ?Comment $parent = null): Comment
    {
        $comment = (new Comment())->setMemory($memory)->setAuthor($author)->setContent('Un souvenir')->setParent($parent);
        $this->em->persist($comment);
        $this->em->flush();

        return $comment;
    }

    private function toggle(Comment $comment, User $user): void
    {
        $this->client->request('POST', '/api/secure/comments/' . $comment->getId() . '/like', server: $this->authHeaders($user));
        $this->assertResponseIsSuccessful();
    }

    /** @return array<int, array<string, mixed>> */
    private function liked(User $user): array
    {
        $this->client->request('GET', '/api/secure/notifications/comments', server: $this->authHeaders($user));

        return array_values(array_filter($this->json(), fn ($n) => $n['type'] === 'comment_liked'));
    }

    public function testRegroupementEtContrat(): void
    {
        [$b, $a, $c] = [$this->createUser(), $this->createUser(), $this->createUser()];
        $memory = $this->createMemory($b);
        $comment = $this->addComment($memory, $b);

        $this->toggle($comment, $a);
        $n = $this->liked($b);
        $this->assertCount(1, $n);
        $this->assertSame(1, $n[0]['likesCount']);
        $this->assertSame($comment->getId(), $n[0]['commentId']);
        $this->assertSame($memory->getId(), $n[0]['memoryId']);
        $this->assertSame($a->getDisplayName(), $n[0]['actorDisplayName']);
        $this->assertNull($n[0]['reason']);
        $this->assertNull($n[0]['message']);

        $this->toggle($comment, $c);
        $n = $this->liked($b);
        $this->assertCount(1, $n);
        $this->assertSame(2, $n[0]['likesCount']);
        $this->assertSame($c->getDisplayName(), $n[0]['actorDisplayName']);
    }

    public function testLikeDeSoiMemeEtReponse(): void
    {
        [$b, $a] = [$this->createUser(), $this->createUser()];
        $memory = $this->createMemory($b);
        $root = $this->addComment($memory, $b);
        $reply = $this->addComment($memory, $b, $root);

        $this->toggle($root, $b);
        $this->assertCount(0, $this->liked($b));

        $this->toggle($reply, $a);
        $n = $this->liked($b);
        $this->assertCount(1, $n);
        $this->assertSame($reply->getId(), $n[0]['commentId']);
    }

    public function testLikeUnlikeLikeSansDoublonNiGonflement(): void
    {
        [$b, $a, $c] = [$this->createUser(), $this->createUser(), $this->createUser()];
        $comment = $this->addComment($this->createMemory($b), $b);

        $this->toggle($comment, $a);
        $this->toggle($comment, $a); // unlike : plus aucun liker, la notification disparaît
        $this->assertCount(0, $this->liked($b));
        $this->toggle($comment, $a);
        $this->assertSame(1, $this->liked($b)[0]['likesCount']);

        $this->toggle($comment, $c);
        $this->toggle($comment, $a); // unlike de A : reste C
        $n = $this->liked($b);
        $this->assertCount(1, $n);
        $this->assertSame([1, $c->getDisplayName()], [$n[0]['likesCount'], $n[0]['actorDisplayName']]);
        $this->toggle($comment, $a);
        $this->assertSame(2, $this->liked($b)[0]['likesCount']);
        $this->assertSame(1, $this->em->getRepository(Notification::class)->count(['type' => NotificationType::COMMENT_LIKED]));
    }

    public function testApresVueUnNouveauLikeCreeUneNouvelleNotification(): void
    {
        [$b, $a, $c] = [$this->createUser(), $this->createUser(), $this->createUser()];
        $comment = $this->addComment($this->createMemory($b), $b);

        $this->toggle($comment, $a);
        $id = $this->liked($b)[0]['id'];
        $this->client->request('POST', '/api/secure/notifications/comments/' . $id . '/seen', server: $this->authHeaders($b));
        $this->assertCount(0, $this->liked($b));

        $this->toggle($comment, $c);
        $n = $this->liked($b);
        $this->assertCount(1, $n);
        $this->assertNotSame($id, $n[0]['id']);
        $this->assertSame(1, $n[0]['likesCount']);

        // Retirer un like d'avant la vue ne touche pas à la notification vue
        $this->toggle($comment, $a);
        $this->assertSame(1, $this->liked($b)[0]['likesCount']);
    }

    public function testMasquageRetireLaNotification(): void
    {
        [$b, $a] = [$this->createUser(), $this->createUser()];
        $memory = $this->createMemory($b);
        $reply = $this->addComment($memory, $b, $this->addComment($memory, $a));

        $this->toggle($reply, $a);
        $this->assertCount(1, $this->liked($b));

        self::getContainer()->get(CommentManager::class)->hide($this->em->getRepository(Comment::class)->find($reply->getId()));
        $this->em->flush();
        $this->assertCount(0, $this->liked($b));
    }

    public function testSuppressionDuLikerRetireSaContribution(): void
    {
        [$b, $a, $c] = [$this->createUser(), $this->createUser(), $this->createUser()];
        $comment = $this->addComment($this->createMemory($b), $b);
        $this->toggle($comment, $a);
        $this->toggle($comment, $c);

        $this->deleteAccount($a);
        $n = $this->liked($b);
        $this->assertSame([1, $c->getDisplayName()], [$n[0]['likesCount'], $n[0]['actorDisplayName']]);

        $this->deleteAccount($c);
        $this->assertCount(0, $this->liked($b));
    }

    public function testLesAutresTypesNePortentPasDeLikesCount(): void
    {
        [$b, $a] = [$this->createUser(), $this->createUser()];
        $memory = $this->createMemory($b);
        $this->client->request('POST', '/api/secure/memory/' . $memory->getId() . '/comments', server: $this->authHeaders($a) + ['CONTENT_TYPE' => 'application/json'], content: json_encode(['content' => 'Salut']));
        $this->client->request('GET', '/api/secure/notifications/comments', server: $this->authHeaders($b));
        $this->assertNull($this->json()[0]['likesCount']);
    }
}
