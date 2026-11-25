<?php

namespace App\Tests\Api;

use App\Entity\Comment;
use App\Entity\Memory;
use App\Entity\Report;
use App\Entity\User;
use App\Enum\CommentStatus;
use App\Enum\PictureStatus;
use App\Enum\ReportReason;
use App\Enum\ReportStatus;
use App\Enum\ReportTargetType;
use App\Tests\Support\ApiTestCase;

class ReportTest extends ApiTestCase
{
    private function addComment(Memory $memory, ?User $author, ?Comment $parent = null): Comment
    {
        $comment = (new Comment())->setMemory($memory)->setAuthor($author)->setContent('Contenu litigieux')->setParent($parent);
        $this->em->persist($comment);
        $this->em->flush();

        return $comment;
    }

    private function report(User $user, array $body): void
    {
        $this->client->request('POST', '/api/secure/reports', server: $this->authHeaders($user), content: json_encode($body));
    }

    public function testSignalerExigeUnJwt(): void
    {
        $this->client->request('POST', '/api/secure/reports', content: '{}');
        $this->assertResponseStatusCodeSame(401);
    }

    public function testSignalerUnCommentaire(): void
    {
        $author = $this->createUser();
        $reporter = $this->createUser();
        $comment = $this->addComment($this->createMemory($author), $author);

        $this->report($reporter, ['targetType' => 'comment', 'targetId' => $comment->getId(), 'reason' => 'spam', 'details' => '  Pub  ']);
        $this->assertResponseStatusCodeSame(201);
        $data = $this->json();
        $this->assertSame(['comment', $comment->getId(), 'spam', 'open'], [$data['targetType'], $data['targetId'], $data['reason'], $data['status']]);

        $report = $this->em->find(Report::class, $data['id']);
        $this->assertSame('Pub', $report->getDetails());
        $this->assertSame($reporter->getId(), $report->getReporter()->getId());
    }

    public function testDoublonRenvoie409(): void
    {
        $author = $this->createUser();
        $reporter = $this->createUser();
        $other = $this->createUser();
        $comment = $this->addComment($this->createMemory($author), $author);
        $body = ['targetType' => 'comment', 'targetId' => $comment->getId(), 'reason' => 'offensive'];

        $this->report($reporter, $body);
        $this->assertResponseStatusCodeSame(201);
        $this->report($reporter, ['reason' => 'spam'] + $body);
        $this->assertResponseStatusCodeSame(409);
        // Un autre utilisateur peut signaler le même contenu
        $this->report($other, $body);
        $this->assertResponseStatusCodeSame(201);
        $this->assertSame(2, $this->em->getRepository(Report::class)->count([]));
    }

    public function testCibleInexistanteOuNonSignalable404(): void
    {
        $author = $this->createUser();
        $reporter = $this->createUser();
        $memory = $this->createMemory($author);
        $hidden = $this->addComment($memory, $author)->setStatus(CommentStatus::HIDDEN);
        $deleted = $this->addComment($memory, null)->setDeletedAt(new \DateTimeImmutable());
        $this->em->flush();

        foreach ([999999, $hidden->getId(), $deleted->getId()] as $id) {
            $this->report($reporter, ['targetType' => 'comment', 'targetId' => $id, 'reason' => 'spam']);
            $this->assertResponseStatusCodeSame(404);
        }
    }

    public function testAutoSignalementInterdit(): void
    {
        $author = $this->createUser();
        $comment = $this->addComment($this->createMemory($this->createUser()), $author);

        $this->report($author, ['targetType' => 'comment', 'targetId' => $comment->getId(), 'reason' => 'spam']);
        $this->assertResponseStatusCodeSame(403);
        $this->assertSame(0, $this->em->getRepository(Report::class)->count([]));
    }

    public function testValidationDuCorps(): void
    {
        $author = $this->createUser();
        $reporter = $this->createUser();
        $comment = $this->addComment($this->createMemory($author), $author);
        $valid = ['targetType' => 'comment', 'targetId' => $comment->getId(), 'reason' => 'spam'];

        foreach ([
            ['targetType' => 'inconnu'], ['targetType' => null],
            ['targetId' => 'abc'], ['targetId' => 0], ['targetId' => null],
            ['reason' => 'nope'], ['reason' => null],
            ['details' => str_repeat('a', 501)], ['details' => ['x']],
        ] as $override) {
            $this->report($reporter, $override + $valid);
            $this->assertResponseStatusCodeSame(422, json_encode($override));
        }

        $this->report($reporter, ['details' => str_repeat('é', 500)] + $valid);
        $this->assertResponseStatusCodeSame(201);

        $this->client->request('POST', '/api/secure/reports', server: $this->authHeaders($reporter), content: 'pas du json');
        $this->assertResponseStatusCodeSame(400);
    }

    public function testSupprimerUnUtilisateurSupprimeSesSignalements(): void
    {
        $author = $this->createUser();
        $reporter = $this->createUser();
        $comment = $this->addComment($this->createMemory($author), $author);
        $this->report($reporter, ['targetType' => 'comment', 'targetId' => $comment->getId(), 'reason' => 'spam']);
        $this->assertSame(1, $this->em->getRepository(Report::class)->count([]));

        $this->em->remove($this->em->find(User::class, $reporter->getId()));
        $this->em->flush();
        $this->assertSame(0, $this->em->getRepository(Report::class)->count([]));
    }

    private function openReport(Comment $comment, User $reporter, ReportReason $reason = ReportReason::SPAM): Report
    {
        $report = (new Report(ReportTargetType::COMMENT, $comment->getId(), $reason))->setReporter($reporter);
        $this->em->persist($report);
        $this->em->flush();

        return $report;
    }

    public function testLaFileDuBackEstReserveAuxAdmins(): void
    {
        $this->client->request('GET', '/back/reports/');
        $this->assertResponseRedirects();

        $this->client->loginUser($this->createUser(['ROLE_USER']));
        $this->client->request('GET', '/back/reports/');
        $this->assertResponseStatusCodeSame(403);
    }

    public function testLaFileAfficheLesSignalementsOuvertsAvecApercu(): void
    {
        $author = $this->createUser();
        $comment = $this->addComment($this->createMemory($author), $author);
        $this->openReport($comment, $this->createUser(), ReportReason::OFFENSIVE);
        $handled = $this->openReport($this->addComment($comment->getMemory(), $author), $this->createUser());
        $handled->markHandled(null);
        $this->em->flush();

        $this->client->loginUser($this->createUser(['ROLE_ADMIN']));
        $crawler = $this->client->request('GET', '/back/reports/');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorCount(1, 'tbody tr');
        $this->assertSelectorTextContains('tbody tr', 'Contenu litigieux');
        $this->assertSelectorTextContains('tbody tr', 'Contenu offensant');
        $this->assertSelectorTextContains('tbody tr', 'Le Panthéon');
    }

    /** Soumet l'action de la file pour le premier signalement (formulaire avec son jeton CSRF). */
    private function submitAction(int $reportId, string $action): void
    {
        $crawler = $this->client->request('GET', '/back/reports/');
        $form = $crawler->filter('button[formaction$="/back/reports/' . $reportId . '/' . $action . '"]')->form();
        $this->client->submit($form);
    }

    public function testMasquerLeCommentaireClotTousSesSignalements(): void
    {
        $author = $this->createUser();
        $comment = $this->addComment($this->createMemory($author), $author);
        $first = $this->openReport($comment, $this->createUser());
        $second = $this->openReport($comment, $this->createUser());
        [$commentId, $firstId, $secondId, $memoryId] = [$comment->getId(), $first->getId(), $second->getId(), $comment->getMemory()->getId()];
        $admin = $this->createUser(['ROLE_ADMIN']);

        $this->client->loginUser($admin);
        $this->submitAction($firstId, 'hide');
        $this->assertResponseRedirects('/back/reports/');

        $this->em->clear();
        $this->assertSame(CommentStatus::HIDDEN, $this->em->find(Comment::class, $commentId)->getStatus());
        foreach ([$firstId, $secondId] as $id) {
            $report = $this->em->find(Report::class, $id);
            $this->assertSame(ReportStatus::HANDLED, $report->getStatus());
            $this->assertSame($admin->getId(), $report->getHandledBy()->getId());
            $this->assertNotNull($report->getHandledAt());
        }

        $this->client->request('GET', '/api/memory/' . $memoryId . '/comments');
        $this->assertSame([], $this->json()['comments']);
    }

    public function testSupprimerLeCommentaireAppliqueLesReglesDeSuppression(): void
    {
        $author = $this->createUser();
        $comment = $this->addComment($this->createMemory($author), $author);
        $this->addComment($comment->getMemory(), $this->createUser(), $comment);
        $report = $this->openReport($comment, $this->createUser());
        [$commentId, $reportId] = [$comment->getId(), $report->getId()];

        $this->client->loginUser($this->createUser(['ROLE_ADMIN']));
        $this->submitAction($reportId, 'delete');

        $this->em->clear();
        // Racine avec réponse : conservé mais vidé
        $comment = $this->em->find(Comment::class, $commentId);
        $this->assertTrue($comment->isDeleted());
        $this->assertSame('', $comment->getContent());
        $this->assertSame(ReportStatus::HANDLED, $this->em->find(Report::class, $reportId)->getStatus());
    }

    public function testIgnorerLaisseLeContenuEnLigne(): void
    {
        $author = $this->createUser();
        $comment = $this->addComment($this->createMemory($author), $author);
        $first = $this->openReport($comment, $this->createUser());
        $second = $this->openReport($comment, $this->createUser());
        [$commentId, $firstId, $secondId] = [$comment->getId(), $first->getId(), $second->getId()];

        $this->client->loginUser($this->createUser(['ROLE_ADMIN']));
        $this->submitAction($firstId, 'ignore');

        $this->em->clear();
        $this->assertSame(CommentStatus::VISIBLE, $this->em->find(Comment::class, $commentId)->getStatus());
        $this->assertSame(ReportStatus::HANDLED, $this->em->find(Report::class, $firstId)->getStatus());
        $this->assertSame(ReportStatus::OPEN, $this->em->find(Report::class, $secondId)->getStatus());
    }

    public function testCibleDejaSupprimeePeutEtreCloturee(): void
    {
        $author = $this->createUser();
        $comment = $this->addComment($this->createMemory($author), $author);
        $report = $this->openReport($comment, $this->createUser());
        $reportId = $report->getId();
        $this->em->remove($comment);
        $this->em->flush();

        $this->client->loginUser($this->createUser(['ROLE_ADMIN']));
        $crawler = $this->client->request('GET', '/back/reports/');
        $this->assertSelectorTextContains('tbody tr', 'déjà supprimé');
        $this->submitAction($reportId, 'ignore');

        $this->em->clear();
        $this->assertSame(ReportStatus::HANDLED, $this->em->find(Report::class, $reportId)->getStatus());
    }

    public function testJetonCsrfInvalideRefuse(): void
    {
        $author = $this->createUser();
        $comment = $this->addComment($this->createMemory($author), $author);
        $report = $this->openReport($comment, $this->createUser());
        $reportId = $report->getId();

        $this->client->loginUser($this->createUser(['ROLE_ADMIN']));
        $this->client->request('POST', '/back/reports/' . $reportId . '/ignore', ['_token' => 'faux']);

        $this->em->clear();
        $this->assertSame(ReportStatus::OPEN, $this->em->find(Report::class, $reportId)->getStatus());
    }

    public function testSignalerUnSouvenir(): void
    {
        $author = $this->createUser();
        $reporter = $this->createUser();
        $memory = $this->createMemory($author);
        $body = ['targetType' => 'memory', 'targetId' => $memory->getId(), 'reason' => 'copyright'];

        $this->report($author, $body);
        $this->assertResponseStatusCodeSame(403);
        $this->report($reporter, ['targetId' => 999999] + $body);
        $this->assertResponseStatusCodeSame(404);
        $this->report($reporter, $body);
        $this->assertResponseStatusCodeSame(201);
        $this->assertSame(['memory', 'copyright'], [$this->json()['targetType'], $this->json()['reason']]);
        $this->report($reporter, $body);
        $this->assertResponseStatusCodeSame(409);
    }

    public function testSignalerUnePhoto(): void
    {
        $owner = $this->createUser();
        $uploader = $this->createUser();
        $reporter = $this->createUser();
        $memory = $this->createMemory($owner, PictureStatus::APPROVED, $uploader);
        $pending = $this->createMemory($owner, PictureStatus::PENDING, $uploader)->getPictures()->last();
        $rejected = $this->createMemory($owner, PictureStatus::REJECTED, $uploader)->getPictures()->last();
        $approved = $memory->getPictures()->last();
        $pictureBody = fn ($picture) => ['targetType' => 'picture', 'targetId' => $picture->getId(), 'reason' => 'illegal'];

        // Un tiers ne voit pas les photos en attente ou refusées : elles ne sont pas signalables
        $this->report($reporter, $pictureBody($pending));
        $this->assertResponseStatusCodeSame(404);
        $this->report($reporter, $pictureBody($rejected));
        $this->assertResponseStatusCodeSame(404);
        // Pas de signalement de sa propre photo
        $this->report($uploader, $pictureBody($approved));
        $this->assertResponseStatusCodeSame(403);
        // L'auteur du souvenir peut signaler une photo proposée par un tiers
        $this->report($owner, $pictureBody($approved));
        $this->assertResponseStatusCodeSame(201);
        $this->report($reporter, $pictureBody($approved));
        $this->assertResponseStatusCodeSame(201);
        $this->report($reporter, $pictureBody($approved));
        $this->assertResponseStatusCodeSame(409);
    }

    private function reportOn(ReportTargetType $type, int $id, User $reporter, ReportReason $reason): Report
    {
        $report = (new Report($type, $id, $reason))->setReporter($reporter);
        $this->em->persist($report);
        $this->em->flush();

        return $report;
    }

    public function testLaFileMetLesMotifsJuridiquesEnPremier(): void
    {
        $author = $this->createUser();
        $comment = $this->addComment($this->createMemory($author), $author);
        $spam = $this->openReport($comment, $this->createUser(), ReportReason::SPAM);
        $other = $this->openReport($comment, $this->createUser(), ReportReason::OTHER);
        $copyright = $this->openReport($comment, $this->createUser(), ReportReason::COPYRIGHT);
        $illegal = $this->openReport($comment, $this->createUser(), ReportReason::ILLEGAL);
        $expected = [$copyright->getId(), $illegal->getId(), $spam->getId(), $other->getId()];

        $this->client->loginUser($this->createUser(['ROLE_MODERATOR']));
        $crawler = $this->client->request('GET', '/back/reports/');

        $ids = $crawler->filter('tbody tr td:first-child')->each(fn ($td) => (int) ltrim(trim($td->text()), '#'));
        $this->assertSame($expected, $ids);
        $this->assertSelectorCount(2, '.badge-danger');
    }

    public function testLaFileAfficheApercuSouvenirEtPhoto(): void
    {
        $owner = $this->createUser();
        $memory = $this->createMemory($owner, PictureStatus::APPROVED, $this->createUser());
        $picture = $memory->getPictures()->last();
        $this->reportOn(ReportTargetType::MEMORY, $memory->getId(), $this->createUser(), ReportReason::OFFENSIVE);
        $this->reportOn(ReportTargetType::PICTURE, $picture->getId(), $this->createUser(), ReportReason::SPAM);

        $this->client->loginUser($this->createUser(['ROLE_MODERATOR']));
        $this->client->request('GET', '/back/reports/');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorCount(2, 'tbody tr');
        $this->assertSelectorExists('img[src="/uploads/memories/before.png"]');
        $this->assertSelectorExists('img[src="/uploads/memories/after.png"]');
        $this->assertSelectorTextContains('tbody', 'Le Panthéon');
        $this->assertSelectorExists('a[href="/back/memory/' . $memory->getId() . '"]');
    }

    public function testSupprimerUnSouvenirSignaleNettoieLocalisationEtSignalements(): void
    {
        $memory = $this->createMemory($this->createUser());
        [$memoryId, $locationId] = [$memory->getId(), $memory->getLocation()->getId()];
        $first = $this->reportOn(ReportTargetType::MEMORY, $memoryId, $this->createUser(), ReportReason::COPYRIGHT);
        $second = $this->reportOn(ReportTargetType::MEMORY, $memoryId, $this->createUser(), ReportReason::SPAM);
        [$firstId, $secondId] = [$first->getId(), $second->getId()];

        $this->client->loginUser($this->createUser(['ROLE_MODERATOR']));
        $this->submitAction($firstId, 'delete');
        $this->assertResponseRedirects('/back/reports/');

        $this->em->clear();
        $this->assertNull($this->em->find(Memory::class, $memoryId));
        $this->assertNull($this->em->find(\App\Entity\Location::class, $locationId));
        foreach ([$firstId, $secondId] as $id) {
            $this->assertSame(ReportStatus::HANDLED, $this->em->find(Report::class, $id)->getStatus());
        }
    }

    public function testSupprimerUnePhotoSignalee(): void
    {
        $memory = $this->createMemory($this->createUser(), PictureStatus::APPROVED, $this->createUser());
        $picture = $memory->getPictures()->last();
        [$pictureId, $memoryId] = [$picture->getId(), $memory->getId()];
        $reportId = $this->reportOn(ReportTargetType::PICTURE, $pictureId, $this->createUser(), ReportReason::ILLEGAL)->getId();

        $this->client->loginUser($this->createUser(['ROLE_MODERATOR']));
        $this->submitAction($reportId, 'delete');

        $this->em->clear();
        $this->assertNull($this->em->find(\App\Entity\Picture::class, $pictureId));
        $this->assertNotNull($this->em->find(Memory::class, $memoryId));
        $this->assertSame(ReportStatus::HANDLED, $this->em->find(Report::class, $reportId)->getStatus());
    }

    public function testLaDernierePhotoVisibleNePeutPasEtreSupprimee(): void
    {
        $memory = $this->createMemory($this->createUser());
        $only = $memory->getPictures()->first();
        [$pictureId] = [$only->getId()];
        $reportId = $this->reportOn(ReportTargetType::PICTURE, $pictureId, $this->createUser(), ReportReason::COPYRIGHT)->getId();

        $this->client->loginUser($this->createUser(['ROLE_MODERATOR']));
        $this->submitAction($reportId, 'delete');

        $this->em->clear();
        $this->assertNotNull($this->em->find(\App\Entity\Picture::class, $pictureId));
        $this->assertSame(ReportStatus::OPEN, $this->em->find(Report::class, $reportId)->getStatus());
    }

    public function testIgnorerUnSignalementDeSouvenirLeLaisseEnLigne(): void
    {
        $memory = $this->createMemory($this->createUser());
        $memoryId = $memory->getId();
        $reportId = $this->reportOn(ReportTargetType::MEMORY, $memoryId, $this->createUser(), ReportReason::SPAM)->getId();

        $this->client->loginUser($this->createUser(['ROLE_MODERATOR']));
        $this->submitAction($reportId, 'ignore');

        $this->em->clear();
        $this->assertNotNull($this->em->find(Memory::class, $memoryId));
        $this->assertSame(ReportStatus::HANDLED, $this->em->find(Report::class, $reportId)->getStatus());
    }
}
