<?php

namespace App\Tests\Api;

use App\Entity\Comment;
use App\Entity\Memory;
use App\Entity\User;
use App\Enum\CommentStatus;
use App\Tests\Support\ApiTestCase;

class CommentTest extends ApiTestCase
{
    /** Ajoute un commentaire directement en base (sans passer par l'anti-spam de l'API). */
    private function addComment(Memory $memory, ?User $author, string $content = 'Bonjour', ?Comment $parent = null): Comment
    {
        $comment = (new Comment())->setMemory($memory)->setAuthor($author)->setContent($content)->setParent($parent);
        $this->em->persist($comment);
        $this->em->flush();

        return $comment;
    }

    private function post(Memory $memory, User $user, array $body): void
    {
        $this->client->request('POST', '/api/secure/memory/' . $memory->getId() . '/comments', server: $this->authHeaders($user) + ['CONTENT_TYPE' => 'application/json'], content: json_encode($body));
    }

    private function list(Memory $memory, ?User $viewer = null, string $query = ''): array
    {
        $this->client->request('GET', '/api/memory/' . $memory->getId() . '/comments' . $query, server: $viewer ? $this->authHeaders($viewer) : []);
        $this->assertResponseIsSuccessful();

        return $this->json();
    }

    public function testLectureOuverteATousSansEmailNiRoles(): void
    {
        $author = $this->createUser(['ROLE_ADMIN']);
        $memory = $this->createMemory($this->createUser());
        $root = $this->addComment($memory, $author, 'Racine');
        $this->addComment($memory, $author, 'Réponse', $root);

        $data = $this->list($memory);
        $this->assertSame(2, $data['total']);
        $this->assertSame(1, $data['rootsTotal']);
        $this->assertSame('Racine', $data['comments'][0]['content']);
        $this->assertSame(['id' => $author->getId(), 'displayName' => 'Testeur'], $data['comments'][0]['author']);
        $this->assertSame(1, $data['comments'][0]['repliesCount']);
        $this->assertSame('Réponse', $data['comments'][0]['replies'][0]['content']);
        $this->assertSame($root->getId(), $data['comments'][0]['replies'][0]['parentId']);

        $body = $this->client->getResponse()->getContent();
        $this->assertStringNotContainsString($author->getEmail(), $body);
        $this->assertStringNotContainsString('ROLE_', $body);
    }

    public function testListeSouvenirInconnu404(): void
    {
        $this->client->request('GET', '/api/memory/999999/comments');
        $this->assertResponseStatusCodeSame(404);
    }

    public function testPaginationDuPlusRecentAuPlusAncien(): void
    {
        $user = $this->createUser();
        $memory = $this->createMemory($user);
        foreach (['un', 'deux', 'trois'] as $i => $text) {
            $this->addComment($memory, $user, $text)->setCreatedAt(new \DateTimeImmutable('2026-01-0' . ($i + 1)));
        }
        $this->em->flush();

        $page1 = $this->list($memory, null, '?limit=2');
        $this->assertSame(['trois', 'deux'], array_column($page1['comments'], 'content'));
        $page2 = $this->list($memory, null, '?limit=2&page=2');
        $this->assertSame(['un'], array_column($page2['comments'], 'content'));
        $this->assertSame(3, $page2['rootsTotal']);
    }

    public function testCreationExigeUnJwt(): void
    {
        $memory = $this->createMemory($this->createUser());
        $this->client->request('POST', '/api/secure/memory/' . $memory->getId() . '/comments', content: '{"content":"x"}');
        $this->assertResponseStatusCodeSame(401);
    }

    public function testCreationRenvoie201(): void
    {
        $user = $this->createUser();
        $memory = $this->createMemory($this->createUser());

        $this->post($memory, $user, ['content' => '  Joli souvenir  ']);
        $this->assertResponseStatusCodeSame(201);
        $data = $this->json();
        $this->assertSame('Joli souvenir', $data['content']);
        $this->assertSame($memory->getId(), $data['memoryId']);
        $this->assertNull($data['parentId']);
        $this->assertFalse($data['deleted']);
        $this->assertSame($user->getId(), $data['author']['id']);
        $this->assertSame([], $data['replies']);
    }

    public function testValidationDuContenu(): void
    {
        $user = $this->createUser();
        $memory = $this->createMemory($this->createUser());

        foreach ([['content' => '   '], ['content' => ''], [], ['content' => 42], ['content' => str_repeat('a', 1001)]] as $body) {
            $this->post($memory, $user, $body);
            $this->assertResponseStatusCodeSame(422, json_encode($body));
        }
        $this->post($memory, $user, ['content' => str_repeat('é', 1000)]);
        $this->assertResponseStatusCodeSame(201);

        $this->client->request('POST', '/api/secure/memory/' . $memory->getId() . '/comments', server: $this->authHeaders($user), content: 'pas du json');
        $this->assertResponseStatusCodeSame(400);
    }

    public function testCreationSurSouvenirInconnu404(): void
    {
        $this->client->request('POST', '/api/secure/memory/999999/comments', server: $this->authHeaders($this->createUser()), content: '{"content":"x"}');
        $this->assertResponseStatusCodeSame(404);
    }

    public function testReponseEtReponseALaReponseRattacheeAuRacine(): void
    {
        $user = $this->createUser();
        $memory = $this->createMemory($this->createUser());
        $root = $this->addComment($memory, $this->createUser());

        $this->post($memory, $user, ['content' => 'Réponse', 'parentId' => $root->getId()]);
        $this->assertResponseStatusCodeSame(201);
        $replyId = $this->json()['id'];
        $this->assertSame($root->getId(), $this->json()['parentId']);

        $this->post($memory, $user, ['content' => 'Réponse à la réponse', 'parentId' => $replyId]);
        $this->assertResponseStatusCodeSame(201);
        $this->assertSame($root->getId(), $this->json()['parentId']);

        $data = $this->list($memory);
        $this->assertCount(1, $data['comments']);
        $this->assertSame(2, $data['comments'][0]['repliesCount']);
        $this->assertSame(['Réponse', 'Réponse à la réponse'], array_column($data['comments'][0]['replies'], 'content'));
        $this->assertSame(3, $data['total']);
    }

    public function testParentInvalide404(): void
    {
        $user = $this->createUser();
        $memory = $this->createMemory($this->createUser());
        $other = $this->createMemory($this->createUser());
        $foreign = $this->addComment($other, $user);

        foreach ([999999, $foreign->getId(), 'abc'] as $parentId) {
            $this->post($memory, $user, ['content' => 'x', 'parentId' => $parentId]);
            $this->assertResponseStatusCodeSame(404);
        }
    }

    public function testAntiSpamCinqParMinute(): void
    {
        $user = $this->createUser();
        $memory = $this->createMemory($this->createUser());
        for ($i = 0; $i < 5; $i++) {
            $this->post($memory, $user, ['content' => 'c' . $i]);
            $this->assertResponseStatusCodeSame(201);
        }
        $this->post($memory, $user, ['content' => 'de trop']);
        $this->assertResponseStatusCodeSame(429);
        $this->assertGreaterThan(0, (int) $this->client->getResponse()->headers->get('Retry-After'));

        // Un autre utilisateur n'est pas concerné
        $this->post($memory, $this->createUser(), ['content' => 'ok']);
        $this->assertResponseStatusCodeSame(201);
    }

    public function testSeulLAuteurModifie(): void
    {
        $author = $this->createUser();
        $memory = $this->createMemory($this->createUser());
        $comment = $this->addComment($memory, $author, 'Avant');
        $uri = '/api/secure/comments/' . $comment->getId();

        foreach ([$this->createUser(), $this->createUser(['ROLE_ADMIN']), $memory->getUser()] as $other) {
            $this->client->request('PUT', $uri, server: $this->authHeaders($other), content: '{"content":"Piraté"}');
            $this->assertResponseStatusCodeSame(403);
        }

        $this->client->request('PUT', $uri, server: $this->authHeaders($author), content: '{"content":" Après "}');
        $this->assertResponseIsSuccessful();
        $this->assertSame('Après', $this->json()['content']);
        $this->assertNotNull($this->json()['updatedAt']);

        $this->client->request('PUT', $uri, server: $this->authHeaders($author), content: '{"content":""}');
        $this->assertResponseStatusCodeSame(422);
        $this->client->request('PUT', '/api/secure/comments/999999', server: $this->authHeaders($author), content: '{"content":"x"}');
        $this->assertResponseStatusCodeSame(404);
    }

    public function testDroitsDeSuppression(): void
    {
        $author = $this->createUser();
        $memory = $this->createMemory($this->createUser());
        $stranger = $this->createUser();
        // Tout est créé avant la première requête : le kernel vide l'EntityManager entre deux requêtes
        $refused = $this->addComment($memory, $author)->getId();
        $allowed = [];
        foreach ([$author, $this->createUser(['ROLE_ADMIN']), $this->createUser(['ROLE_MODERATOR'])] as $user) {
            $allowed[$this->addComment($memory, $author)->getId()] = $this->authHeaders($user);
        }
        $strangerHeaders = $this->authHeaders($stranger);
        // L'auteur du souvenir n'a pas de droit particulier
        $memoryAuthorHeaders = $this->authHeaders($memory->getUser());

        foreach ([$strangerHeaders, $memoryAuthorHeaders] as $server) {
            $this->client->request('DELETE', '/api/secure/comments/' . $refused, server: $server);
            $this->assertResponseStatusCodeSame(403);
        }

        foreach ($allowed as $id => $server) {
            $this->client->request('DELETE', '/api/secure/comments/' . $id, server: $server);
            $this->assertResponseStatusCodeSame(204);
        }
        $this->em->clear();
        foreach (array_keys($allowed) as $id) {
            $this->assertNull($this->em->find(Comment::class, $id));
        }
        $this->assertNotNull($this->em->find(Comment::class, $refused));
    }

    public function testSuppressionDUnRacineAvecReponsesLeConserveVide(): void
    {
        $author = $this->createUser();
        $replier = $this->createUser();
        $memory = $this->createMemory($this->createUser());
        $root = $this->addComment($memory, $author, 'Secret');
        $reply = $this->addComment($memory, $replier, 'Réponse', $root);

        $this->client->request('DELETE', '/api/secure/comments/' . $root->getId(), server: $this->authHeaders($author));
        $this->assertResponseStatusCodeSame(204);

        $data = $this->list($memory);
        $this->assertSame(1, $data['total']);
        $deleted = $data['comments'][0];
        $this->assertTrue($deleted['deleted']);
        $this->assertNull($deleted['content']);
        $this->assertNull($deleted['author']);
        $this->assertSame(1, $deleted['repliesCount']);
        $this->assertSame('Réponse', $deleted['replies'][0]['content']);
        $this->assertStringNotContainsString('Secret', $this->client->getResponse()->getContent());

        // Déjà supprimé : plus modifiable ni supprimable, on ne peut plus lui répondre
        $this->client->request('DELETE', '/api/secure/comments/' . $root->getId(), server: $this->authHeaders($author));
        $this->assertResponseStatusCodeSame(404);
        $this->post($memory, $replier, ['content' => 'x', 'parentId' => $root->getId()]);
        $this->assertResponseStatusCodeSame(404);

        // La dernière réponse supprimée emporte le racine vide
        $this->client->request('DELETE', '/api/secure/comments/' . $reply->getId(), server: $this->authHeaders($replier));
        $this->assertResponseStatusCodeSame(204);
        $rootId = $root->getId();
        $this->em->clear();
        $this->assertNull($this->em->find(Comment::class, $rootId));
        $this->assertSame([], $this->list($memory)['comments']);
    }

    public function testSuppressionDUneReponseLaSupprimePhysiquement(): void
    {
        $author = $this->createUser();
        $memory = $this->createMemory($this->createUser());
        $root = $this->addComment($memory, $this->createUser());
        $reply = $this->addComment($memory, $author, 'Réponse', $root);
        $replyId = $reply->getId();

        $this->client->request('DELETE', '/api/secure/comments/' . $replyId, server: $this->authHeaders($author));
        $this->assertResponseStatusCodeSame(204);
        $this->em->clear();
        $this->assertNull($this->em->find(Comment::class, $replyId));
        $this->assertNotNull($this->em->find(Comment::class, $root->getId()));
    }

    public function testCommentairesMasques(): void
    {
        $user = $this->createUser();
        $memory = $this->createMemory($this->createUser());
        $hiddenAlone = $this->addComment($memory, $user, 'Masqué seul')->setStatus(CommentStatus::HIDDEN);
        $hiddenRoot = $this->addComment($memory, $user, 'Masqué avec réponse')->setStatus(CommentStatus::HIDDEN);
        $this->addComment($memory, $user, 'Réponse visible', $hiddenRoot);
        $this->addComment($memory, $user, 'Réponse masquée', $hiddenRoot)->setStatus(CommentStatus::HIDDEN);
        $this->em->flush();

        $data = $this->list($memory);
        $this->assertCount(1, $data['comments']);
        $this->assertTrue($data['comments'][0]['deleted']);
        $this->assertNull($data['comments'][0]['content']);
        $this->assertNull($data['comments'][0]['author']);
        $this->assertSame(['Réponse visible'], array_column($data['comments'][0]['replies'], 'content'));
        $this->assertSame(1, $data['total']);
        $this->assertStringNotContainsString('Masqué', $this->client->getResponse()->getContent());

        // Un commentaire masqué n'est plus modifiable par son auteur
        $this->client->request('PUT', '/api/secure/comments/' . $hiddenAlone->getId(), server: $this->authHeaders($user), content: '{"content":"x"}');
        $this->assertResponseStatusCodeSame(403);
    }

    public function testSupprimerUnUtilisateurAnonymiseSesCommentaires(): void
    {
        $author = $this->createUser();
        $memory = $this->createMemory($this->createUser());
        $comment = $this->addComment($memory, $author, 'Je reste');
        $id = $comment->getId();

        $this->em->remove($author);
        $this->em->flush();
        $this->em->clear();

        $this->assertNull($this->em->find(Comment::class, $id)->getAuthor());
        $data = $this->list($this->em->find(Memory::class, $memory->getId()));
        $this->assertSame('Je reste', $data['comments'][0]['content']);
        $this->assertFalse($data['comments'][0]['deleted']);
        $this->assertSame(['id' => null, 'displayName' => 'Utilisateur supprimé'], $data['comments'][0]['author']);
    }

    public function testSupprimerLeSouvenirSupprimeSesCommentairesEtReponses(): void
    {
        $user = $this->createUser();
        $memory = $this->createMemory($user);
        $root = $this->addComment($memory, $user);
        $this->addComment($memory, $user, 'Réponse', $root);

        $this->em->remove($memory);
        $this->em->flush();
        $this->em->clear();

        $this->assertSame(0, $this->em->getRepository(Comment::class)->count([]));
    }

    public function testSupprimerLeSouvenirViaLAPINeCassePas(): void
    {
        $user = $this->createUser();
        $memory = $this->createMemory($user);
        $this->addComment($memory, $this->createUser());
        $id = $memory->getId();

        $this->client->request('DELETE', '/api/secure/delete/memory/' . $id, server: $this->authHeaders($user));
        $this->assertResponseIsSuccessful();
        $this->em->clear();
        $this->assertNull($this->em->find(Memory::class, $id));
        $this->assertSame(0, $this->em->getRepository(Comment::class)->count([]));
    }
}
