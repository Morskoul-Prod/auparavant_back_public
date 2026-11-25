<?php

namespace App\Tests\Api;

use App\Entity\Comment;
use App\Entity\Location;
use App\Entity\Memory;
use App\Entity\Notification;
use App\Entity\Report;
use App\Entity\User;
use App\Enum\ReportReason;
use App\Enum\ReportTargetType;
use App\Tests\Support\ApiTestCase;

class ModerationNotificationTest extends ApiTestCase
{
    private function addComment(Memory $memory, User $author, string $content = 'Propos litigieux', ?Comment $parent = null): Comment
    {
        $comment = (new Comment())->setMemory($memory)->setAuthor($author)->setContent($content)->setParent($parent);
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

    public function testUnModerateurSupprimeUnCommentaireEtPrevientLAuteur(): void
    {
        $author = $this->createUser();
        $moderator = $this->createUser(['ROLE_MODERATOR']);
        $memory = $this->createMemory($this->createUser());
        $comment = $this->addComment($memory, $author);
        [$memoryId, $commentId] = [$memory->getId(), $comment->getId()];
        $headers = $this->authHeaders($moderator);
        $authorHeaders = $this->authHeaders($author);

        $this->client->request('DELETE', '/api/secure/comments/' . $commentId, server: $headers, content: json_encode(['reason' => '  Propos injurieux ']));
        $this->assertResponseStatusCodeSame(204);

        $this->client->request('GET', '/api/secure/notifications/comments', server: $authorHeaders);
        $notifications = $this->json();
        $this->assertCount(1, $notifications);
        $n = $notifications[0];
        $this->assertSame('comment_removed', $n['type']);
        $this->assertSame($memoryId, $n['memoryId']);
        $this->assertSame('Le Panthéon', $n['memoryTitle']);
        $this->assertNull($n['commentId']);
        $this->assertSame('Modération', $n['actorDisplayName']);
        $this->assertSame('Propos litigieux', $n['excerpt']);
        $this->assertSame('Propos injurieux', $n['reason']);
        $this->assertSame([], $this->notifications($moderator));

        // Elle s'acquitte comme les autres
        $this->client->request('POST', '/api/secure/notifications/comments/' . $n['id'] . '/seen', server: $authorHeaders);
        $this->assertResponseIsSuccessful();
        $this->assertSame([], $this->notifications($author));
    }

    public function testSupprimerSonPropreCommentaireNeNotifiePersonne(): void
    {
        $author = $this->createUser();
        $comment = $this->addComment($this->createMemory($this->createUser()), $author);

        $this->client->request('DELETE', '/api/secure/comments/' . $comment->getId(), server: $this->authHeaders($author));
        $this->assertResponseStatusCodeSame(204);
        $this->assertSame([], $this->notifications($author));
    }

    public function testUnAdminSupprimantUnRacineAvecReponsesNotifieLAuteur(): void
    {
        $author = $this->createUser();
        $admin = $this->createUser(['ROLE_ADMIN']);
        $memory = $this->createMemory($this->createUser());
        $root = $this->addComment($memory, $author, 'Racine');
        $this->addComment($memory, $this->createUser(), 'Réponse', $root);
        $adminHeaders = $this->authHeaders($admin);
        $rootId = $root->getId();

        $this->client->request('DELETE', '/api/secure/comments/' . $rootId, server: $adminHeaders);
        $this->assertResponseStatusCodeSame(204);

        $notifications = $this->notifications($author);
        $this->assertSame(['comment_removed'], array_column($notifications, 'type'));
        $this->assertNull($notifications[0]['reason']);
        $this->assertSame('Racine', $notifications[0]['excerpt']);
    }

    public function testLeMotifTropLongEstRefuse(): void
    {
        $author = $this->createUser();
        $comment = $this->addComment($this->createMemory($this->createUser()), $author);

        $this->client->request('DELETE', '/api/secure/comments/' . $comment->getId(), server: $this->authHeaders($this->createUser(['ROLE_ADMIN'])), content: json_encode(['reason' => str_repeat('a', 201)]));
        $this->assertResponseStatusCodeSame(422);
        $this->assertNotNull($this->em->find(Comment::class, $comment->getId()));
    }

    public function testSeulLAuteurSupprimeUnSouvenirParLApi(): void
    {
        $author = $this->createUser();
        $memory = $this->createMemory($author);
        $other = $this->createMemory($author);
        [$memoryId, $otherId, $locationId] = [$memory->getId(), $other->getId(), $other->getLocation()->getId()];

        // Ni un tiers, ni un modérateur, ni un administrateur : la modération se fait depuis le back-office
        foreach ([[], ['ROLE_MODERATOR'], ['ROLE_ADMIN']] as $roles) {
            $this->client->request('DELETE', '/api/secure/delete/memory/' . $memoryId, server: $this->authHeaders($this->createUser($roles)));
            $this->assertResponseStatusCodeSame(403);
        }
        $this->client->request('DELETE', '/api/secure/delete/memory/' . $memoryId);
        $this->assertResponseStatusCodeSame(401);
        $this->em->clear();
        $this->assertNotNull($this->em->find(Memory::class, $memoryId));

        // L'auteur supprime son propre souvenir : aucune notification, la localisation orpheline part avec
        $this->client->request('DELETE', '/api/secure/delete/memory/' . $otherId, server: $this->authHeaders($author));
        $this->assertResponseIsSuccessful();
        $this->assertSame([], $this->notifications($author));
        $this->em->clear();
        $this->assertNotNull($this->em->find(Memory::class, $memoryId));
        $this->assertNull($this->em->find(Memory::class, $otherId));
        $this->assertNull($this->em->find(Location::class, $locationId));
    }

    public function testLaNotificationDeModerationSurvitAuSouvenirSupprime(): void
    {
        $author = $this->createUser();
        $owner = $this->createUser();
        $memory = $this->createMemory($owner);
        $comment = $this->addComment($memory, $author);
        $memoryId = $memory->getId();
        $moderator = $this->authHeaders($this->createUser(['ROLE_MODERATOR']));
        $authorHeaders = $this->authHeaders($author);

        $this->client->request('DELETE', '/api/secure/comments/' . $comment->getId(), server: $moderator);
        $this->client->request('DELETE', '/api/secure/delete/memory/' . $memoryId, server: $this->authHeaders($owner));
        $this->assertResponseIsSuccessful();

        $this->client->request('GET', '/api/secure/notifications/comments', server: $authorHeaders);
        $n = $this->json()[0];
        $this->assertSame('comment_removed', $n['type']);
        $this->assertNull($n['memoryId']);
        $this->assertSame('Le Panthéon', $n['memoryTitle']);
    }

    private function submitAction(int $reportId, string $action): void
    {
        $crawler = $this->client->request('GET', '/back/reports/');
        $this->client->submit($crawler->filter('button[formaction$="/back/reports/' . $reportId . '/' . $action . '"]')->form());
    }

    public function testLaFileDuBackNotifieLAuteurAvecLeMotifDuSignalement(): void
    {
        $author = $this->createUser();
        $memory = $this->createMemory($author);
        $comment = $this->addComment($memory, $author);
        $other = $this->createMemory($author);
        $commentReport = (new Report(ReportTargetType::COMMENT, $comment->getId(), ReportReason::OFFENSIVE))->setReporter($this->createUser());
        $memoryReport = (new Report(ReportTargetType::MEMORY, $other->getId(), ReportReason::COPYRIGHT))->setReporter($this->createUser());
        $this->em->persist($commentReport);
        $this->em->persist($memoryReport);
        $this->em->flush();
        [$commentReportId, $memoryReportId, $otherId] = [$commentReport->getId(), $memoryReport->getId(), $other->getId()];
        $authorHeaders = $this->authHeaders($author);

        $this->client->loginUser($this->createUser(['ROLE_MODERATOR']));
        $this->submitAction($commentReportId, 'hide');
        $this->submitAction($memoryReportId, 'delete');

        $this->client->request('GET', '/api/secure/notifications/comments', server: $authorHeaders);
        $byType = array_column($this->json(), null, 'type');
        $this->assertSame('Contenu offensant', $byType['comment_removed']['reason']);
        $this->assertSame('Droit d\'auteur', $byType['memory_deleted']['reason']);
        $this->em->clear();
        $this->assertNull($this->em->find(Memory::class, $otherId));
    }

    public function testLeBackOfficeAdminNotifieLAuteurDUnSouvenirSupprime(): void
    {
        $author = $this->createUser();
        $memory = $this->createMemory($author);
        $memoryId = $memory->getId();
        $authorHeaders = $this->authHeaders($author);

        $this->client->loginUser($this->createUser(['ROLE_ADMIN']));
        $crawler = $this->client->request('GET', '/back/memory/' . $memoryId);
        $this->client->submit($crawler->filter('form[action="/back/memory/' . $memoryId . '"]')->form());

        $this->em->clear();
        $this->assertNull($this->em->find(Memory::class, $memoryId));
        $this->client->request('GET', '/api/secure/notifications/comments', server: $authorHeaders);
        $this->assertSame(['memory_deleted'], array_column($this->json(), 'type'));
    }

    public function testLesNotificationsDeCommentairesGardentLeurFormat(): void
    {
        $owner = $this->createUser();
        $memory = $this->createMemory($owner);
        $this->client->request('POST', '/api/secure/memory/' . $memory->getId() . '/comments', server: $this->authHeaders($this->createUser()), content: '{"content":"Bonjour"}');

        $n = $this->notifications($owner)[0];
        $this->assertSame(['id', 'type', 'memoryId', 'memoryTitle', 'commentId', 'actorDisplayName', 'excerpt', 'reason', 'message', 'likesCount', 'createdAt'], array_keys($n));
        $this->assertSame('comment_on_memory', $n['type']);
        $this->assertSame('Bonjour', $n['excerpt']);
        $this->assertNull($n['reason']);
        $this->assertSame(1, $this->em->getRepository(Notification::class)->count([]));
    }
}
