<?php

namespace App\Tests\Api;

use App\Entity\Comment;
use App\Entity\Memory;
use App\Entity\User;
use App\Tests\Support\ApiTestCase;

/** Réponse à une réponse : commentaire visé (`replyToId`) et notification de son auteur */
class ReplyToTest extends ApiTestCase
{
    private function post(Memory|int $memory, User $author, array $body): array
    {
        $id = $memory instanceof Memory ? $memory->getId() : $memory;
        $this->client->request('POST', '/api/secure/memory/' . $id . '/comments', server: $this->authHeaders($author), content: json_encode($body));

        return $this->json();
    }

    private function types(User $user): array
    {
        $this->client->request('GET', '/api/secure/notifications/comments', server: $this->authHeaders($user));
        $this->assertResponseIsSuccessful();

        return array_column($this->json(), 'type');
    }

    private function addComment(Memory $memory, User $author, ?Comment $parent = null): Comment
    {
        $comment = (new Comment())->setMemory($memory)->setAuthor($author)->setParent($parent)->setContent('Texte');
        $this->em->persist($comment);
        $this->em->flush();

        return $comment;
    }

    /** Souvenir de $owner, racine de $rootAuthor, réponse de $replyAuthor */
    private function thread(User $owner, User $rootAuthor, User $replyAuthor): array
    {
        $memory = $this->createMemory($owner);
        $root = $this->addComment($memory, $rootAuthor);
        $reply = $this->addComment($memory, $replyAuthor, $root);

        return [$memory, $root, $reply];
    }

    public function testRepondreAUneReponseNotifieLAuteurVisePuisLeRacineEtLeSouvenir(): void
    {
        [$owner, $rootAuthor, $replyAuthor, $me] = [$this->createUser(), $this->createUser(), $this->createUser(), $this->createUser()];
        [$memory, $root, $reply] = $this->thread($owner, $rootAuthor, $replyAuthor);
        [$rootId, $replyId] = [$root->getId(), $reply->getId()];

        $data = $this->post($memory, $me, ['content' => '@Testeur merci', 'parentId' => $rootId, 'replyToId' => $replyId]);
        $this->assertResponseStatusCodeSame(201);
        $this->assertSame($rootId, $data['parentId']);
        $this->assertSame($replyId, $data['replyToId']);
        $this->assertSame('Testeur', $data['replyToDisplayName']);

        $this->assertSame(['reply_to_comment'], $this->types($replyAuthor));
        $this->assertSame(['reply_to_comment'], $this->types($rootAuthor));
        $this->assertSame(['comment_on_memory'], $this->types($owner));
        $this->assertSame([], $this->types($me));
    }

    public function testLeFilSeDeduitDeReplyToIdSansParentId(): void
    {
        [$memory, $root, $reply] = $this->thread($this->createUser(), $this->createUser(), $this->createUser());
        [$rootId, $replyId] = [$root->getId(), $reply->getId()];

        $data = $this->post($memory, $this->createUser(), ['content' => 'Hop', 'replyToId' => $replyId]);
        $this->assertResponseStatusCodeSame(201);
        $this->assertSame([$rootId, $replyId], [$data['parentId'], $data['replyToId']]);
    }

    public function testUneSeuleNotificationQuandLAuteurViseEstAussiLeRacineOuLeProprietaire(): void
    {
        // visé = auteur du racine
        [$owner, $same, $me] = [$this->createUser(), $this->createUser(), $this->createUser()];
        [$memory, $root, $reply] = $this->thread($owner, $same, $same);
        $this->post($memory, $me, ['content' => 'a', 'replyToId' => $reply->getId()]);
        $this->assertResponseStatusCodeSame(201);
        $this->assertSame(['reply_to_comment'], $this->types($same));
        $this->assertSame(['comment_on_memory'], $this->types($owner));
    }

    public function testLAuteurViseQuiPossedeLeSouvenirNeRecoitQuUneNotificationReplyToComment(): void
    {
        [$owner, $rootAuthor, $me] = [$this->createUser(), $this->createUser(), $this->createUser()];
        $memory = $this->createMemory($owner);
        $root = $this->addComment($memory, $rootAuthor);
        $reply = $this->addComment($memory, $owner, $root);
        $this->post($memory, $me, ['content' => 'a', 'replyToId' => $reply->getId()]);

        $this->assertSame(['reply_to_comment'], $this->types($owner));
        $this->assertSame(['reply_to_comment'], $this->types($rootAuthor));
    }

    public function testAutoReponseNeNotifiePersonne(): void
    {
        $me = $this->createUser();
        [$memory, $root, $reply] = $this->thread($me, $me, $me);
        $this->post($memory, $me, ['content' => 'moi', 'replyToId' => $reply->getId()]);
        $this->assertResponseStatusCodeSame(201);
        $this->assertSame([], $this->types($me));
    }

    public function testReplyToIdInvalideDonne404(): void
    {
        [$memory, $root, $reply] = $this->thread($this->createUser(), $this->createUser(), $this->createUser());
        $otherMemory = $this->createMemory($this->createUser());
        $foreign = $this->addComment($otherMemory, $this->createUser());
        $otherRoot = $this->addComment($memory, $this->createUser());
        $otherThreadReply = $this->addComment($memory, $this->createUser(), $otherRoot);
        $hidden = $this->addComment($memory, $this->createUser(), $root)->setHidden(true);
        $tombstone = $this->addComment($memory, $this->createUser(), $root)->setDeletedAt(new \DateTimeImmutable());
        $this->em->flush();
        $me = $this->createUser();
        [$memoryId, $rootId] = [$memory->getId(), $root->getId()];
        $bad = [$foreign->getId(), $otherThreadReply->getId(), $hidden->getId(), $tombstone->getId(), 999999, 'x'];

        foreach ($bad as $replyToId) {
            $this->post($memoryId, $me, ['content' => 'x', 'parentId' => $rootId, 'replyToId' => $replyToId]);
            $this->assertResponseStatusCodeSame(404, 'replyToId ' . $replyToId);
        }
        // sans parentId, un commentaire d'un autre souvenir est introuvable aussi
        $this->post($memoryId, $me, ['content' => 'x', 'replyToId' => $foreign->getId()]);
        $this->assertResponseStatusCodeSame(404);
    }

    public function testViserLeRacineEquivautAUneReponseDirecte(): void
    {
        [$memory, $root] = $this->thread($this->createUser(), $this->createUser(), $this->createUser());
        $rootId = $root->getId();

        $data = $this->post($memory, $this->createUser(), ['content' => 'direct', 'parentId' => $rootId, 'replyToId' => $rootId]);
        $this->assertResponseStatusCodeSame(201);
        $this->assertSame($rootId, $data['parentId']);
        $this->assertNull($data['replyToId']);
        $this->assertNull($data['replyToDisplayName']);
    }

    public function testSansReplyToIdLeComportementEstInchange(): void
    {
        [$memory, $root] = $this->thread($this->createUser(), $this->createUser(), $this->createUser());
        $data = $this->post($memory, $this->createUser(), ['content' => 'classique', 'parentId' => $root->getId()]);
        $this->assertResponseStatusCodeSame(201);
        $this->assertArrayHasKey('replyToId', $data);
        $this->assertNull($data['replyToId']);
    }

    public function testSuppressionDuCommentaireViseGardeLaReponse(): void
    {
        [$memory, $root, $reply] = $this->thread($this->createUser(), $this->createUser(), $author = $this->createUser());
        [$memoryId, $replyId] = [$memory->getId(), $reply->getId()];
        $me = $this->createUser();
        $mine = $this->post($memory, $me, ['content' => 'je vise', 'replyToId' => $replyId]);
        $this->assertSame($replyId, $mine['replyToId']);

        $this->client->request('DELETE', '/api/secure/comments/' . $replyId, server: $this->authHeaders($author));
        $this->assertResponseStatusCodeSame(204);

        $this->client->request('GET', '/api/memory/' . $memoryId . '/comments');
        $replies = $this->json()['comments'][0]['replies'];
        $this->assertCount(1, $replies);
        $this->assertSame($mine['id'], $replies[0]['id']);
        $this->assertNull($replies[0]['replyToId']);
        $this->assertNull($replies[0]['replyToDisplayName']);
    }

    public function testLesCleSontDansLaListeEtAuteurSupprimeEstAnonymise(): void
    {
        [$memory, $root, $reply] = $this->thread($this->createUser(), $this->createUser(), $gone = $this->createUser());
        $memoryId = $memory->getId();
        $mine = $this->post($memory, $this->createUser(), ['content' => 'je vise', 'replyToId' => $reply->getId()]);

        $this->em->clear();
        $this->em->remove($this->em->find(User::class, $gone->getId()));
        $this->em->flush();

        $this->client->request('GET', '/api/memory/' . $memoryId . '/comments');
        $comments = $this->json()['comments'];
        $this->assertArrayHasKey('replyToId', $comments[0]);
        $this->assertNull($comments[0]['replyToId']);
        $replies = array_column($comments[0]['replies'], null, 'id');
        $this->assertArrayHasKey('replyToDisplayName', $replies[$reply->getId()] ?? $replies[array_key_first($replies)]);
        $viser = $replies[$mine['id']];
        $this->assertSame('Utilisateur supprimé', $viser['replyToDisplayName']);
    }

    public function testSupprimerLeSouvenirAvecUneReponseQuiEnVisaitUneAutre(): void
    {
        [$memory, $root, $reply] = $this->thread($owner = $this->createUser(), $this->createUser(), $this->createUser());
        $this->post($memory, $this->createUser(), ['content' => 'je vise', 'replyToId' => $reply->getId()]);
        $this->assertResponseStatusCodeSame(201);
        $memoryId = $memory->getId();

        // les commentaires partent avec le souvenir malgré le lien replyTo entre deux réponses
        $this->client->request('DELETE', '/api/secure/delete/memory/' . $memoryId, server: $this->authHeaders($owner));
        $this->assertResponseIsSuccessful();
        $this->em->clear();
        $this->assertSame(0, $this->em->getRepository(Comment::class)->count([]));
    }

    public function testSupprimerLesReponsesUneAUneApresLeRacineVide(): void
    {
        [$memory, $root, $reply] = $this->thread($this->createUser(), $rootAuthor = $this->createUser(), $replyAuthor = $this->createUser());
        $mine = $this->post($memory, $me = $this->createUser(), ['content' => 'je vise', 'replyToId' => $reply->getId()]);
        [$rootId, $replyId] = [$root->getId(), $reply->getId()];
        $admin = $this->authHeaders($this->createUser(['ROLE_ADMIN']));

        // racine vidé (gardé pour ses réponses), puis ses réponses ; la dernière emporte le racine
        $this->client->request('DELETE', '/api/secure/comments/' . $rootId, server: $admin);
        $this->assertResponseStatusCodeSame(204);
        $this->client->request('DELETE', '/api/secure/comments/' . $replyId, server: $admin);
        $this->assertResponseStatusCodeSame(204);
        $this->client->request('DELETE', '/api/secure/comments/' . $mine['id'], server: $admin);
        $this->assertResponseStatusCodeSame(204);

        $this->em->clear();
        $this->assertSame(0, $this->em->getRepository(Comment::class)->count([]));
    }
}
