<?php

namespace App\Tests\Api;

use App\Entity\Comment;
use App\Entity\Memory;
use App\Entity\User;
use App\Tests\Support\ApiTestCase;

class ModerationMessageTest extends ApiTestCase
{
    private function addComment(Memory $memory, User $author, string $content = 'Propos litigieux'): Comment
    {
        $comment = (new Comment())->setMemory($memory)->setAuthor($author)->setContent($content);
        $this->em->persist($comment);
        $this->em->flush();

        return $comment;
    }

    private function notifications(User $user): array
    {
        $this->client->request('GET', '/api/secure/notifications/comments', server: $this->authHeaders($user));
        $this->assertResponseIsSuccessful();

        return $this->json();
    }

    public function testSupprimerUnCommentaireAvecObjetEtMessage(): void
    {
        $author = $this->createUser();
        $comment = $this->addComment($this->createMemory($this->createUser()), $author);
        $moderator = $this->createUser(['ROLE_MODERATOR']);

        $this->client->request('DELETE', '/api/secure/comments/' . $comment->getId(), server: $this->authHeaders($moderator), content: json_encode([
            'reason' => 'Propos injurieux',
            'message' => "  Merci de rester courtois.\nVoir la charte.  ",
        ]));
        $this->assertResponseStatusCodeSame(204);

        $n = $this->notifications($author)[0];
        $this->assertSame('comment_removed', $n['type']);
        $this->assertSame('Propos injurieux', $n['reason']);
        $this->assertSame("Merci de rester courtois.\nVoir la charte.", $n['message']);
    }

    public function testMessageVideOuAbsentDonneNull(): void
    {
        $author = $this->createUser();
        $first = $this->addComment($this->createMemory($this->createUser()), $author, 'Premier');
        $second = $this->addComment($first->getMemory(), $author, 'Second');
        $headers = $this->authHeaders($this->createUser(['ROLE_MODERATOR']));

        $this->client->request('DELETE', '/api/secure/comments/' . $first->getId(), server: $headers, content: json_encode(['reason' => 'Spam', 'message' => '   ']));
        $this->assertResponseStatusCodeSame(204);
        // sans corps : rien ne casse
        $this->client->request('DELETE', '/api/secure/comments/' . $second->getId(), server: $headers);
        $this->assertResponseStatusCodeSame(204);

        $notifications = $this->notifications($author);
        $this->assertCount(2, $notifications);
        foreach ($notifications as $n) {
            $this->assertArrayHasKey('message', $n);
            $this->assertNull($n['message']);
        }
    }

    public function testMessageTropLongRefuse(): void
    {
        $author = $this->createUser();
        $comment = $this->addComment($this->createMemory($this->createUser()), $author);
        $commentId = $comment->getId();
        $headers = $this->authHeaders($this->createUser(['ROLE_ADMIN']));

        $this->client->request('DELETE', '/api/secure/comments/' . $commentId, server: $headers, content: json_encode(['message' => str_repeat('a', 501)]));
        $this->assertResponseStatusCodeSame(422);
        $this->assertStringContainsString('500', $this->json()['error']);

        // 500 caractères passent, et rien n'a été supprimé par le refus
        $this->assertNotNull($this->em->find(Comment::class, $commentId));
        $this->client->request('DELETE', '/api/secure/comments/' . $commentId, server: $headers, content: json_encode(['message' => str_repeat('é', 500)]));
        $this->assertResponseStatusCodeSame(204);
        $this->assertSame(500, mb_strlen($this->notifications($author)[0]['message']));
    }

    public function testAucuneNotificationQuandLActeurEstLAuteur(): void
    {
        $author = $this->createUser();
        $comment = $this->addComment($this->createMemory($this->createUser()), $author);
        $memory = $this->createMemory($author);
        [$commentId, $memoryId] = [$comment->getId(), $memory->getId()];
        $headers = $this->authHeaders($author);

        $this->client->request('DELETE', '/api/secure/comments/' . $commentId, server: $headers, content: json_encode(['reason' => 'x', 'message' => 'y']));
        $this->assertResponseStatusCodeSame(204);
        $this->client->request('DELETE', '/api/secure/delete/memory/' . $memoryId, server: $headers);
        $this->assertResponseIsSuccessful();

        $this->assertSame([], $this->notifications($author));
    }

    public function testLaCleMessageEstPresenteSurTousLesTypes(): void
    {
        $author = $this->createUser();
        $other = $this->createUser();
        $memory = $this->createMemory($author);
        $root = $this->addComment($memory, $author, 'Racine');
        $removed = $this->addComment($memory, $author, 'Retiré');
        [$memoryId, $rootId, $removedId] = [$memory->getId(), $root->getId(), $removed->getId()];
        $otherHeaders = $this->authHeaders($other);
        $moderatorHeaders = $this->authHeaders($this->createUser(['ROLE_MODERATOR']));

        // commentaire d'un autre sur le souvenir de l'auteur, puis réponse à l'auteur
        $this->client->request('POST', '/api/secure/memory/' . $memoryId . '/comments', server: $otherHeaders, content: json_encode(['content' => 'Bravo']));
        $this->assertResponseStatusCodeSame(201);
        $this->client->request('POST', '/api/secure/memory/' . $memoryId . '/comments', server: $otherHeaders, content: json_encode(['content' => 'Réponse', 'parentId' => $rootId]));
        $this->assertResponseStatusCodeSame(201);
        $this->client->request('DELETE', '/api/secure/comments/' . $removedId, server: $moderatorHeaders, content: json_encode(['message' => 'Hors charte']));
        $this->assertResponseStatusCodeSame(204);

        $byType = [];
        foreach ($this->notifications($author) as $n) {
            $this->assertArrayHasKey('message', $n);
            $byType[$n['type']] = $n['message'];
        }
        $this->assertSame('Hors charte', $byType['comment_removed']);
        $this->assertNull($byType['comment_on_memory']);
        $this->assertNull($byType['reply_to_comment']);
    }
}
