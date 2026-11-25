<?php

namespace App\Tests\Back;

use App\Entity\Comment;
use App\Entity\Memory;
use App\Entity\Picture;
use App\Entity\Report;
use App\Entity\User;
use App\Enum\PictureStatus;
use App\Enum\ReportReason;
use App\Enum\ReportStatus;
use App\Enum\ReportTargetType;
use App\Service\ModerationInput;
use App\Tests\Support\ApiTestCase;
use Symfony\Component\HttpFoundation\Request;

/** Objet et message libre saisis par le modérateur dans le back-office */
class ModerationMessageTest extends ApiTestCase
{
    private function comment(Memory $memory, User $author): Comment
    {
        $comment = (new Comment())->setMemory($memory)->setAuthor($author)->setContent('Propos litigieux');
        $this->em->persist($comment);
        $this->em->flush();

        return $comment;
    }

    private function report(ReportTargetType $type, int $targetId, ReportReason $reason = ReportReason::OFFENSIVE): int
    {
        $report = (new Report($type, $targetId, $reason))->setReporter($this->createUser());
        $this->em->persist($report);
        $this->em->flush();

        return $report->getId();
    }

    /** Soumet le formulaire d'une page du BO (action se terminant par $suffix) avec les valeurs données */
    private function submit(string $page, string $suffix, array $values = []): void
    {
        $crawler = $this->client->request('GET', $page);
        $form = $crawler->filter('form[action$="' . $suffix . '"], button[formaction$="' . $suffix . '"]')->first()->form();
        foreach ($values as $name => $value) {
            $form[$name] = $value;
        }
        $this->client->submit($form);
    }

    private function notificationsOf(User $user): array
    {
        $this->client->request('GET', '/api/secure/notifications/comments', server: $this->authHeaders($user));

        return $this->json();
    }

    public function testSupprimerUnSouvenirSignaleAvecObjetEtMessage(): void
    {
        $author = $this->createUser();
        $memory = $this->createMemory($author);
        $reportId = $this->report(ReportTargetType::MEMORY, $memory->getId(), ReportReason::COPYRIGHT);
        [$memoryId, $authorHeaders] = [$memory->getId(), $this->authHeaders($author)];

        $this->client->loginUser($this->createUser(['ROLE_MODERATOR']));
        $this->submit('/back/reports/', '/back/reports/' . $reportId . '/delete', [
            'reason' => 'Photo protégée',
            'message' => 'Merci de ne publier que vos propres photos.',
        ]);
        $this->assertResponseRedirects('/back/reports/');

        $this->em->clear();
        $this->assertNull($this->em->find(Memory::class, $memoryId));
        $n = $this->notificationsOf($author)[0];
        $this->assertSame('memory_deleted', $n['type']);
        $this->assertSame('Photo protégée', $n['reason']);
        $this->assertSame('Merci de ne publier que vos propres photos.', $n['message']);
    }

    public function testUnSeulJeuDeChampsParSignalementPourLesTroisActions(): void
    {
        $author = $this->createUser();
        $comment = $this->comment($this->createMemory($author), $author);
        $reportId = $this->report(ReportTargetType::COMMENT, $comment->getId());

        $this->client->loginUser($this->createUser(['ROLE_MODERATOR']));
        $this->client->request('GET', '/back/reports/');

        $this->assertSelectorCount(1, 'tbody tr form');
        $this->assertSelectorCount(1, 'tbody textarea[name=message]');
        $this->assertSelectorCount(1, 'tbody input[name=reason]');
        foreach (['hide', 'delete', 'ignore'] as $action) {
            $this->assertSelectorCount(1, 'button[formaction$="/back/reports/' . $reportId . '/' . $action . '"]');
        }
    }

    public function testMessageVideDonneNullEtObjetParDefautConserve(): void
    {
        $author = $this->createUser();
        $comment = $this->comment($this->createMemory($author), $author);
        $reportId = $this->report(ReportTargetType::COMMENT, $comment->getId(), ReportReason::SPAM);
        $authorHeaders = $this->authHeaders($author);

        $this->client->loginUser($this->createUser(['ROLE_MODERATOR']));
        $this->submit('/back/reports/', '/back/reports/' . $reportId . '/hide', ['message' => '   ']);

        $n = $this->notificationsOf($author)[0];
        $this->assertSame('comment_removed', $n['type']);
        $this->assertSame('Spam', $n['reason']);
        $this->assertNull($n['message']);
    }

    public function testMasquerAvecObjetEtMessage(): void
    {
        $author = $this->createUser();
        $comment = $this->comment($this->createMemory($author), $author);
        $reportId = $this->report(ReportTargetType::COMMENT, $comment->getId());
        $authorHeaders = $this->authHeaders($author);

        $this->client->loginUser($this->createUser(['ROLE_MODERATOR']));
        $this->submit('/back/reports/', '/back/reports/' . $reportId . '/hide', ['reason' => 'Ton agressif', 'message' => 'Reformulez et republiez.']);

        $n = $this->notificationsOf($author)[0];
        $this->assertSame(['Ton agressif', 'Reformulez et republiez.'], [$n['reason'], $n['message']]);
    }

    public function testMessageDe501CaracteresRefuse(): void
    {
        $author = $this->createUser();
        $memory = $this->createMemory($author);
        $reportId = $this->report(ReportTargetType::MEMORY, $memory->getId());
        [$memoryId, $authorHeaders] = [$memory->getId(), $this->authHeaders($author)];

        $this->client->loginUser($this->createUser(['ROLE_MODERATOR']));
        $this->submit('/back/reports/', '/back/reports/' . $reportId . '/delete', ['message' => str_repeat('a', 501)]);
        $this->assertSame([], $this->notificationsOf($author));
        $this->em->clear();
        $this->assertNotNull($this->em->find(Memory::class, $memoryId));
        $this->assertSame(ReportStatus::OPEN, $this->em->find(Report::class, $reportId)->getStatus());
    }

    public function testObjetDe201CaracteresRefuse(): void
    {
        $author = $this->createUser();
        $memory = $this->createMemory($author);
        $reportId = $this->report(ReportTargetType::MEMORY, $memory->getId());
        $memoryId = $memory->getId();

        $this->client->loginUser($this->createUser(['ROLE_MODERATOR']));
        $this->submit('/back/reports/', '/back/reports/' . $reportId . '/delete', ['reason' => str_repeat('a', 201)]);
        $this->em->clear();
        $this->assertNotNull($this->em->find(Memory::class, $memoryId));
    }

    public function testLesErreursDeLongueurSontLisibles(): void
    {
        // Le navigateur de test ne conserve pas les messages flash : le texte affiché au modérateur est vérifié ici
        $tooLong = Request::create('/', 'POST', ['reason' => str_repeat('a', 201), 'message' => 'ok']);
        $this->assertSame('Le motif ne peut pas dépasser 200 caractères.', ModerationInput::fromRequest($tooLong)->error);

        $tooLong = Request::create('/', 'POST', ['reason' => 'ok', 'message' => str_repeat('é', 501)]);
        $this->assertSame('Le message ne peut pas dépasser 500 caractères.', ModerationInput::fromRequest($tooLong)->error);

        $fine = ModerationInput::fromRequest(Request::create('/', 'POST', ['reason' => ' ', 'message' => str_repeat('é', 500)]), 'Spam');
        $this->assertNull($fine->error);
        $this->assertNull($fine->reason);
        $this->assertSame(500, mb_strlen($fine->message));
        // champ absent : objet par défaut
        $this->assertSame('Spam', ModerationInput::fromRequest(Request::create('/', 'POST'), 'Spam')->reason);
    }

    public function testDemasquerAvecObjetEtMessage(): void
    {
        $author = $this->createUser();
        $comment = $this->comment($this->createMemory($author), $author)->setHidden(true, $this->createUser(['ROLE_ADMIN']));
        $this->em->flush();
        [$commentId, $authorHeaders] = [$comment->getId(), $this->authHeaders($author)];

        $this->client->loginUser($this->createUser(['ROLE_MODERATOR']));
        $this->submit('/back/comments/hidden/', '/back/comments/hidden/' . $commentId . '/unhide', ['reason' => 'Masquage levé', 'message' => 'Après relecture, rien à redire.']);

        $n = $this->notificationsOf($author)[0];
        $this->assertSame('comment_restored', $n['type']);
        $this->assertSame(['Masquage levé', 'Après relecture, rien à redire.'], [$n['reason'], $n['message']]);
    }

    public function testSupprimerUnSouvenirDepuisLaFicheAvecMessage(): void
    {
        $author = $this->createUser();
        $memory = $this->createMemory($author);
        [$memoryId, $authorHeaders] = [$memory->getId(), $this->authHeaders($author)];

        $this->client->loginUser($this->createUser(['ROLE_ADMIN']));
        $this->submit('/back/memory/' . $memoryId, '/back/memory/' . $memoryId, ['reason' => 'Doublon', 'message' => 'Ce lieu existe déjà.']);

        $n = $this->notificationsOf($author)[0];
        $this->assertSame(['memory_deleted', 'Doublon', 'Ce lieu existe déjà.'], [$n['type'], $n['reason'], $n['message']]);
    }

    public function testAucuneNotificationQuandLActeurEstLAuteur(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);
        $memory = $this->createMemory($admin);
        $comment = $this->comment($this->createMemory($this->createUser()), $admin);
        [$memoryId, $commentId, $adminHeaders] = [$memory->getId(), $comment->getId(), $this->authHeaders($admin)];

        $this->client->loginUser($admin);
        // la fiche ne propose pas de message à l'auteur de son propre souvenir : un envoi forcé ne notifie pas non plus
        $crawler = $this->client->request('GET', '/back/memory/' . $memoryId);
        $this->assertSelectorNotExists('form[action="/back/memory/' . $memoryId . '"] textarea[name=message]');
        // champ absent du formulaire : on poste directement les valeurs du formulaire plus un message
        $form = $crawler->filter('form[action="/back/memory/' . $memoryId . '"]')->form();
        $this->client->request('POST', $form->getUri(), $form->getPhpValues() + ['message' => 'x']);
        $this->client->request('DELETE', '/api/secure/comments/' . $commentId, server: $adminHeaders, content: json_encode(['reason' => 'x', 'message' => 'y']));

        $this->assertSame([], $this->notificationsOf($admin));
    }

    public function testSupprimerUnePhotoSignaleePrevientCeluiQuiLAvaitProposee(): void
    {
        $uploader = $this->createUser();
        $memory = $this->createMemory($this->createUser(), PictureStatus::APPROVED, $uploader);
        $picture = $memory->getPictures()->last();
        $reportId = $this->report(ReportTargetType::PICTURE, $picture->getId(), ReportReason::COPYRIGHT);
        [$memoryId, $pictureId, $uploaderHeaders] = [$memory->getId(), $picture->getId(), $this->authHeaders($uploader)];

        $this->client->loginUser($this->createUser(['ROLE_MODERATOR']));
        $this->submit('/back/reports/', '/back/reports/' . $reportId . '/delete', ['reason' => 'Photo protégée', 'message' => 'Proposez plutôt une photo de vous.']);

        $this->em->clear();
        $this->assertNull($this->em->find(Picture::class, $pictureId));
        $n = $this->notificationsOf($uploader)[0];
        $this->assertSame('picture_removed', $n['type']);
        $this->assertSame($memoryId, $n['memoryId']);
        $this->assertSame('Le Panthéon', $n['memoryTitle']);
        $this->assertNull($n['commentId']);
        $this->assertNull($n['excerpt']);
        $this->assertSame('Modération', $n['actorDisplayName']);
        $this->assertSame(['Photo protégée', 'Proposez plutôt une photo de vous.'], [$n['reason'], $n['message']]);
    }

    public function testAucunePhotoNotifieeQuandLeModerateurLAvaitProposee(): void
    {
        $moderator = $this->createUser(['ROLE_MODERATOR']);
        $memory = $this->createMemory($this->createUser(), PictureStatus::APPROVED, $moderator);
        $reportId = $this->report(ReportTargetType::PICTURE, $memory->getPictures()->last()->getId());

        $this->client->loginUser($moderator);
        $this->submit('/back/reports/', '/back/reports/' . $reportId . '/delete');

        $this->assertSame([], $this->notificationsOf($moderator));
    }
}
