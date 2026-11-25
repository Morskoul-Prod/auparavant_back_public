<?php

namespace App\Tests\Api;

use App\Entity\Comment;
use App\Entity\Memory;
use App\Entity\Notification;
use App\Entity\User;
use App\Tests\Support\ApiTestCase;

class CommentNotificationTest extends ApiTestCase
{
    private function comment(Memory $memory, User $author, string $content = 'Bonjour', ?int $parentId = null): int
    {
        $this->client->request('POST', '/api/secure/memory/' . $memory->getId() . '/comments', server: $this->authHeaders($author), content: json_encode(['content' => $content, 'parentId' => $parentId]));
        $this->assertResponseStatusCodeSame(201);

        return $this->json()['id'];
    }

    private function notifications(User $user): array
    {
        $this->client->request('GET', '/api/secure/notifications/comments', server: $this->authHeaders($user));
        $this->assertResponseIsSuccessful();

        return $this->json();
    }

    public function testLesNotificationsDeCommentairesExigentUnJwt(): void
    {
        $this->client->request('GET', '/api/secure/notifications/comments');
        $this->assertResponseStatusCodeSame(401);
        $this->client->request('POST', '/api/secure/notifications/comments/1/seen');
        $this->assertResponseStatusCodeSame(401);
    }

    public function testCommentaireRacineNotifieLAuteurDuSouvenir(): void
    {
        $owner = $this->createUser();
        $visitor = $this->createUser();
        $memory = $this->createMemory($owner);
        $commentId = $this->comment($memory, $visitor, 'Un commentaire');

        $notifications = $this->notifications($owner);
        $this->assertCount(1, $notifications);
        $n = $notifications[0];
        $this->assertSame('comment_on_memory', $n['type']);
        $this->assertSame($memory->getId(), $n['memoryId']);
        $this->assertSame('Le Panthéon', $n['memoryTitle']);
        $this->assertSame($commentId, $n['commentId']);
        $this->assertSame('Testeur', $n['actorDisplayName']);
        $this->assertSame('Un commentaire', $n['excerpt']);
        $this->assertNotEmpty($n['createdAt']);
        $this->assertSame([], $this->notifications($visitor));
    }

    public function testPasDAutoNotification(): void
    {
        $owner = $this->createUser();
        $memory = $this->createMemory($owner);
        $rootId = $this->comment($memory, $owner);
        $this->comment($memory, $owner, 'Réponse à moi-même', $rootId);

        $this->assertSame([], $this->notifications($owner));
    }

    public function testReponseNotifieLAuteurDuRacineEtLAuteurDuSouvenir(): void
    {
        $owner = $this->createUser();
        $rootAuthor = $this->createUser();
        $replier = $this->createUser();
        $memory = $this->createMemory($owner);
        $rootId = $this->comment($memory, $rootAuthor);
        $this->comment($memory, $replier, 'Réponse', $rootId);

        $this->assertSame(['reply_to_comment'], array_column($this->notifications($rootAuthor), 'type'));
        // Le propriétaire a reçu le commentaire racine puis la réponse
        $this->assertSame(['comment_on_memory', 'comment_on_memory'], array_column($this->notifications($owner), 'type'));
        $this->assertSame([], $this->notifications($replier));
    }

    public function testSansDoublonQuandLAuteurDuRacineEstLAuteurDuSouvenir(): void
    {
        $owner = $this->createUser();
        $replier = $this->createUser();
        $memory = $this->createMemory($owner);
        $rootId = $this->comment($memory, $owner);
        $this->comment($memory, $replier, 'Réponse', $rootId);

        $notifications = $this->notifications($owner);
        $this->assertCount(1, $notifications);
        $this->assertSame('reply_to_comment', $notifications[0]['type']);
    }

    public function testReponseALaReponseNotifieLAuteurDuRacine(): void
    {
        $owner = $this->createUser();
        $rootAuthor = $this->createUser();
        $a = $this->createUser();
        $b = $this->createUser();
        $memory = $this->createMemory($owner);
        $rootId = $this->comment($memory, $rootAuthor);
        $replyId = $this->comment($memory, $a, 'Réponse', $rootId);
        $this->comment($memory, $b, 'Réponse à la réponse', $replyId);

        $this->assertCount(2, $this->notifications($rootAuthor));
        // Modèle à un seul niveau : l'auteur de la première réponse n'est pas notifié
        $this->assertSame([], $this->notifications($a));
    }

    public function testExtraitTronque(): void
    {
        $owner = $this->createUser();
        $memory = $this->createMemory($owner);
        $this->comment($memory, $this->createUser(), str_repeat('é', 150));

        $excerpt = $this->notifications($owner)[0]['excerpt'];
        $this->assertSame(str_repeat('é', 100) . '…', $excerpt);
    }

    public function testSeenEstIdempotentEtReserveAuDestinataire(): void
    {
        $owner = $this->createUser();
        $other = $this->createUser();
        $memory = $this->createMemory($owner);
        $this->comment($memory, $other);
        $id = $this->notifications($owner)[0]['id'];
        $uri = '/api/secure/notifications/comments/' . $id . '/seen';

        $this->client->request('POST', $uri, server: $this->authHeaders($other));
        $this->assertResponseStatusCodeSame(404);
        $this->assertCount(1, $this->notifications($owner));

        foreach ([1, 2] as $_) {
            $this->client->request('POST', $uri, server: $this->authHeaders($owner));
            $this->assertResponseIsSuccessful();
        }
        $this->assertSame([], $this->notifications($owner));

        $this->client->request('POST', '/api/secure/notifications/comments/999999/seen', server: $this->authHeaders($owner));
        $this->assertResponseStatusCodeSame(404);
    }

    public function testLeContratDesRefusDePhotosEstInchange(): void
    {
        $owner = $this->createUser();
        $memory = $this->createMemory($owner);
        $this->comment($memory, $this->createUser());

        $this->client->request('GET', '/api/secure/notifications', server: $this->authHeaders($owner));
        $this->assertSame([], $this->json());
    }

    public function testSupprimerUnCommentaireSupprimeSesNotifications(): void
    {
        $owner = $this->createUser();
        $visitor = $this->createUser();
        $memory = $this->createMemory($owner);
        $commentId = $this->comment($memory, $visitor);

        $this->client->request('DELETE', '/api/secure/comments/' . $commentId, server: $this->authHeaders($visitor));
        $this->assertSame([], $this->notifications($owner));
        $this->assertSame(0, $this->em->getRepository(Notification::class)->count([]));
    }

    public function testSupprimerUnRacineAvecReponsesSupprimeSesNotifications(): void
    {
        $owner = $this->createUser();
        $rootAuthor = $this->createUser();
        $replier = $this->createUser();
        $memory = $this->createMemory($owner);
        $rootId = $this->comment($memory, $rootAuthor);
        $this->comment($memory, $replier, 'Réponse', $rootId);

        $this->client->request('DELETE', '/api/secure/comments/' . $rootId, server: $this->authHeaders($rootAuthor));
        // Il ne reste que la notification de la réponse (le racine, vidé, n'en a plus)
        $this->assertSame(['comment_on_memory'], array_column($this->notifications($owner), 'type'));
        $this->assertCount(1, $this->notifications($rootAuthor));
    }

    public function testMasquerUnCommentaireSupprimeSesNotifications(): void
    {
        $owner = $this->createUser();
        $memory = $this->createMemory($owner);
        $commentId = $this->comment($memory, $this->createUser());
        $this->assertCount(1, $this->notifications($owner));

        $comment = $this->em->find(Comment::class, $commentId);
        static::getContainer()->get(\App\Service\CommentManager::class)->hide($comment);
        $this->em->flush();

        $this->assertSame([], $this->notifications($owner));
    }

    public function testActeurSupprimeAfficheUtilisateurSupprime(): void
    {
        $owner = $this->createUser();
        $visitor = $this->createUser();
        $memory = $this->createMemory($owner);
        $this->comment($memory, $visitor);

        $this->em->remove($this->em->find(User::class, $visitor->getId()));
        $this->em->flush();

        $this->assertSame('Utilisateur supprimé', $this->notifications($owner)[0]['actorDisplayName']);
    }

    public function testSupprimerLeDestinataireOuLeSouvenirSupprimeLesNotifications(): void
    {
        $owner = $this->createUser();
        $memory = $this->createMemory($owner);
        $this->comment($memory, $this->createUser());
        $this->assertSame(1, $this->em->getRepository(Notification::class)->count([]));

        $this->em->remove($this->em->find(Memory::class, $memory->getId()));
        $this->em->flush();
        $this->assertSame(0, $this->em->getRepository(Notification::class)->count([]));
    }
}
