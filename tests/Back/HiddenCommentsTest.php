<?php

namespace App\Tests\Back;

use App\Entity\Comment;
use App\Entity\Memory;
use App\Entity\Notification;
use App\Entity\Report;
use App\Entity\User;
use App\Enum\CommentStatus;
use App\Enum\NotificationType;
use App\Enum\ReportReason;
use App\Enum\ReportTargetType;
use App\Tests\Support\ApiTestCase;

class HiddenCommentsTest extends ApiTestCase
{
    private function comment(Memory $memory, ?User $author, string $content = 'Propos litigieux', ?Comment $parent = null): Comment
    {
        $comment = (new Comment())->setMemory($memory)->setAuthor($author)->setContent($content)->setParent($parent);
        $this->em->persist($comment);
        $this->em->flush();

        return $comment;
    }

    private function submit(string $action, int $commentId): void
    {
        $crawler = $this->client->request('GET', '/back/comments/hidden/');
        $this->client->submit($crawler->filter('form[action$="/back/comments/hidden/' . $commentId . '/' . $action . '"]')->form());
    }

    public function testLaPageEstReserveeAuxModerateurs(): void
    {
        $this->client->request('GET', '/back/comments/hidden/');
        $this->assertResponseRedirects();

        $this->client->loginUser($this->createUser(['ROLE_USER']));
        $this->client->request('GET', '/back/comments/hidden/');
        $this->assertResponseStatusCodeSame(403);

        $this->client->loginUser($this->createUser(['ROLE_MODERATOR']));
        $this->client->request('GET', '/back/comments/hidden/');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('a[href="/back/comments/hidden/"]');
    }

    public function testLaListeNeMontreQueLesCommentairesMasquesRecentsEnPremier(): void
    {
        $author = $this->createUser();
        $memory = $this->createMemory($author);
        $this->comment($memory, $author, 'Visible');
        $old = $this->comment($memory, $author, 'Masqué ancien')->setHidden(true);
        $recent = $this->comment($memory, $author, 'Masqué récent')->setHidden(true);
        $recent->setHidden(true, $this->createUser(['ROLE_ADMIN']));
        $this->em->flush();
        // Un racine masqué puis supprimé (vidé) n'a plus rien à rétablir
        $tomb = $this->comment($memory, $author, '')->setHidden(true)->setDeletedAt(new \DateTimeImmutable());
        $this->em->flush();
        $ids = [$recent->getId(), $old->getId()];

        $this->client->loginUser($this->createUser(['ROLE_MODERATOR']));
        $crawler = $this->client->request('GET', '/back/comments/hidden/');

        $this->assertSelectorCount(2, 'tbody tr');
        $this->assertSelectorTextNotContains('tbody', 'Visible');
        $this->assertSame($ids, $crawler->filter('tbody tr td:first-child')->each(fn ($td) => (int) ltrim(trim($td->text()), '#')));
        $this->assertSelectorTextContains('tbody', 'Testeur');
        $this->assertNotNull($tomb->getId());
    }

    public function testPagination(): void
    {
        $author = $this->createUser();
        $memory = $this->createMemory($author);
        for ($i = 0; $i < 21; $i++) {
            $this->comment($memory, $author, 'c' . $i)->setHidden(true);
        }
        $this->em->flush();

        $this->client->loginUser($this->createUser(['ROLE_MODERATOR']));
        $this->client->request('GET', '/back/comments/hidden/');
        $this->assertSelectorCount(20, 'tbody tr');
        $this->client->request('GET', '/back/comments/hidden/?page=2');
        $this->assertSelectorCount(1, 'tbody tr');
    }

    public function testMasquerEnregistreLaDateEtLeModerateur(): void
    {
        $author = $this->createUser();
        $moderator = $this->createUser(['ROLE_MODERATOR']);
        $memory = $this->createMemory($author);
        $comment = $this->comment($memory, $author);
        $report = (new Report(ReportTargetType::COMMENT, $comment->getId(), ReportReason::OFFENSIVE))->setReporter($this->createUser());
        $this->em->persist($report);
        $this->em->flush();
        [$commentId, $reportId, $moderatorId] = [$comment->getId(), $report->getId(), $moderator->getId()];

        $this->client->loginUser($moderator);
        $crawler = $this->client->request('GET', '/back/reports/');
        $this->client->submit($crawler->filter('button[formaction$="/back/reports/' . $reportId . '/hide"]')->form());

        $this->em->clear();
        $comment = $this->em->find(Comment::class, $commentId);
        $this->assertSame(CommentStatus::HIDDEN, $comment->getStatus());
        $this->assertNotNull($comment->getHiddenAt());
        $this->assertSame($moderatorId, $comment->getHiddenBy()->getId());

        $this->client->request('GET', '/back/comments/hidden/');
        $this->assertSelectorTextContains('tbody', 'par Testeur');
    }

    public function testDemasquerRetablitLeCommentaireEtPrevientLAuteur(): void
    {
        $author = $this->createUser();
        $owner = $this->createUser();
        $memory = $this->createMemory($owner);
        $root = $this->comment($memory, $author, 'Mon commentaire');
        $this->comment($memory, $this->createUser(), 'Réponse', $root);
        $root->setHidden(true, $this->createUser(['ROLE_ADMIN']));
        $this->em->flush();
        [$rootId, $memoryId] = [$root->getId(), $memory->getId()];
        $authorHeaders = $this->authHeaders($author);

        $this->client->loginUser($this->createUser(['ROLE_MODERATOR']));
        $this->submit('unhide', $rootId);
        $this->assertResponseRedirects('/back/comments/hidden/');

        $this->em->clear();
        $comment = $this->em->find(Comment::class, $rootId);
        $this->assertSame(CommentStatus::VISIBLE, $comment->getStatus());
        $this->assertNull($comment->getHiddenAt());
        $this->assertNull($comment->getHiddenBy());

        // De nouveau publié avec son contenu et ses réponses
        $this->client->request('GET', '/api/memory/' . $memoryId . '/comments');
        $first = $this->json()['comments'][0];
        $this->assertFalse($first['deleted']);
        $this->assertSame('Mon commentaire', $first['content']);
        $this->assertSame(1, $first['repliesCount']);

        // L'auteur est prévenu
        $this->client->request('GET', '/api/secure/notifications/comments', server: $authorHeaders);
        $notifications = $this->json();
        $this->assertCount(1, $notifications);
        $this->assertSame('comment_restored', $notifications[0]['type']);
        $this->assertSame($rootId, $notifications[0]['commentId']);
        $this->assertSame($memoryId, $notifications[0]['memoryId']);
        $this->assertSame('Mon commentaire', $notifications[0]['excerpt']);
        $this->assertSame('Modération', $notifications[0]['actorDisplayName']);

        $this->client->request('GET', '/back/comments/hidden/');
        $this->assertSelectorCount(0, 'tbody tr');
    }

    public function testSiLeModerateurEstLAuteurAucuneNotification(): void
    {
        $moderator = $this->createUser(['ROLE_MODERATOR']);
        $memory = $this->createMemory($this->createUser());
        $comment = $this->comment($memory, $moderator)->setHidden(true);
        $this->em->flush();
        $commentId = $comment->getId();

        $this->client->loginUser($moderator);
        $this->submit('unhide', $commentId);

        $this->assertSame(0, $this->em->getRepository(Notification::class)->count(['type' => NotificationType::COMMENT_RESTORED]));
    }

    public function testSupprimerUnCommentaireMasqueNeRenotifiePas(): void
    {
        $author = $this->createUser();
        $memory = $this->createMemory($this->createUser());
        $comment = $this->comment($memory, $author);
        $commentId = $comment->getId();
        $moderator = $this->createUser(['ROLE_MODERATOR']);
        $moderatorHeaders = $this->authHeaders($moderator);
        $authorHeaders = $this->authHeaders($author);

        // Masqué par la file (notification comment_removed), puis supprimé depuis la page des masqués
        $manager = static::getContainer()->get(\App\Service\CommentManager::class);
        $manager->hide($this->em->find(Comment::class, $commentId), $moderator, 'Injures');
        $this->em->flush();

        $this->client->loginUser($this->em->find(User::class, $moderator->getId()));
        $this->submit('delete', $commentId);

        $this->em->clear();
        $this->assertNull($this->em->find(Comment::class, $commentId));
        $this->client->request('GET', '/api/secure/notifications/comments', server: $authorHeaders);
        $this->assertSame(['comment_removed'], array_column($this->json(), 'type'));
        $this->assertSame('Injures', $this->json()[0]['reason']);
        $this->assertNotEmpty($moderatorHeaders);
    }

    public function testJetonInvalideOuCommentaireDejaVisibleSontRefuses(): void
    {
        $author = $this->createUser();
        $memory = $this->createMemory($author);
        $hidden = $this->comment($memory, $author)->setHidden(true);
        $visible = $this->comment($memory, $author);
        $this->em->flush();
        [$hiddenId, $visibleId] = [$hidden->getId(), $visible->getId()];

        $this->client->loginUser($this->createUser(['ROLE_MODERATOR']));
        $this->client->request('POST', '/back/comments/hidden/' . $hiddenId . '/unhide', ['_token' => 'faux']);
        $this->client->request('POST', '/back/comments/hidden/' . $visibleId . '/delete', ['_token' => 'faux']);

        $this->em->clear();
        $this->assertSame(CommentStatus::HIDDEN, $this->em->find(Comment::class, $hiddenId)->getStatus());
        $this->assertNotNull($this->em->find(Comment::class, $visibleId));
    }

    public function testSeulsLesModerateursPeuventAgir(): void
    {
        $author = $this->createUser();
        $comment = $this->comment($this->createMemory($author), $author)->setHidden(true);
        $this->em->flush();
        $id = $comment->getId();

        $this->client->loginUser($this->createUser(['ROLE_USER']));
        $this->client->request('POST', '/back/comments/hidden/' . $id . '/unhide', ['_token' => 'x']);
        $this->assertResponseStatusCodeSame(403);
    }
}
