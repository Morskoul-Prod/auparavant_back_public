<?php

namespace App\Tests\Back;

use App\Entity\Comment;
use App\Entity\Memory;
use App\Entity\Report;
use App\Entity\User;
use App\Enum\ReportReason;
use App\Enum\ReportTargetType;
use App\Service\ModerationNotifier;
use App\Tests\Support\ApiTestCase;

/** Notification neutre « signalement examiné » adressée à l'auteur d'un signalement */
class ReportReviewedTest extends ApiTestCase
{
    private function comment(Memory $memory, User $author): Comment
    {
        $comment = (new Comment())->setMemory($memory)->setAuthor($author)->setContent('Propos litigieux');
        $this->em->persist($comment);
        $this->em->flush();

        return $comment;
    }

    private function report(ReportTargetType $type, int $targetId, User $reporter, ReportReason $reason = ReportReason::OFFENSIVE): int
    {
        $report = (new Report($type, $targetId, $reason))->setReporter($reporter);
        $this->em->persist($report);
        $this->em->flush();

        return $report->getId();
    }

    private function act(string $action, int $reportId): void
    {
        $crawler = $this->client->request('GET', '/back/reports/');
        $this->client->submit($crawler->filter('button[formaction$="/back/reports/' . $reportId . '/' . $action . '"]')->form());
    }

    private function notificationsOf(User $user): array
    {
        $this->client->request('GET', '/api/secure/notifications/comments', server: $this->authHeaders($user));

        return $this->json();
    }

    private function reviewed(User $user): array
    {
        return array_values(array_filter($this->notificationsOf($user), fn ($n) => $n['type'] === 'report_reviewed'));
    }

    public function testIgnorerMasquerEtSupprimerDonnentLaMemeNotification(): void
    {
        $author = $this->createUser();
        $memory = $this->createMemory($author);
        $ignored = $this->comment($memory, $author);
        $hidden = $this->comment($memory, $author);
        $other = $this->createMemory($author);
        [$ignoreRep, $hideRep, $deleteRep] = [
            $this->report(ReportTargetType::COMMENT, $ignored->getId(), $r1 = $this->createUser(), ReportReason::SPAM),
            $this->report(ReportTargetType::COMMENT, $hidden->getId(), $r2 = $this->createUser(), ReportReason::ILLEGAL),
            $this->report(ReportTargetType::MEMORY, $other->getId(), $r3 = $this->createUser(), ReportReason::COPYRIGHT),
        ];

        $this->client->loginUser($this->createUser(['ROLE_MODERATOR']));
        $this->act('ignore', $ignoreRep);
        $this->act('hide', $hideRep);
        $this->act('delete', $deleteRep);

        $contents = [];
        foreach ([$r1, $r2, $r3] as $reporter) {
            $list = $this->reviewed($reporter);
            $this->assertCount(1, $list);
            $n = $list[0];
            unset($n['id'], $n['createdAt']);
            $contents[] = $n;
        }
        $this->assertSame($contents[0], $contents[1]);
        $this->assertSame($contents[0], $contents[2]);
        $this->assertSame([
            'type' => 'report_reviewed', 'memoryId' => null, 'memoryTitle' => null, 'commentId' => null,
            'actorDisplayName' => 'Modération', 'excerpt' => null, 'reason' => null, 'message' => null, 'likesCount' => null,
        ], $contents[0]);
    }

    public function testChaqueAuteurDeSignalementRecoitUneSeuleNotification(): void
    {
        $author = $this->createUser();
        $comment = $this->comment($this->createMemory($author), $author);
        $first = $this->createUser();
        $second = $this->createUser();
        $firstReport = $this->report(ReportTargetType::COMMENT, $comment->getId(), $first, ReportReason::SPAM);
        $this->report(ReportTargetType::COMMENT, $comment->getId(), $second, ReportReason::OFFENSIVE);

        $this->client->loginUser($this->createUser(['ROLE_MODERATOR']));
        $crawler = $this->client->request('GET', '/back/reports/');
        $form = $crawler->filter('button[formaction$="/back/reports/' . $firstReport . '/hide"]')->form();
        $this->client->submit($form);
        // action rejouée : le signalement est déjà traité, rien de plus
        $this->client->submit($form);

        $this->assertCount(1, $this->reviewed($first));
        $this->assertCount(1, $this->reviewed($second));
        // l'auteur du contenu reçoit comment_removed, pas report_reviewed
        $this->assertSame([], $this->reviewed($author));
        $this->assertSame(['comment_removed'], array_column($this->notificationsOf($author), 'type'));
    }

    public function testSignalementDejaTraiteOuCibleDisparueNeNotifiePas(): void
    {
        $author = $this->createUser();
        $memory = $this->createMemory($author);
        $gone = $this->createMemory($author);
        $reporter = $this->createUser();
        $late = $this->createUser();
        $handled = $this->report(ReportTargetType::MEMORY, $memory->getId(), $reporter);
        $goneReport = $this->report(ReportTargetType::MEMORY, $gone->getId(), $late);
        $goneId = $gone->getId();
        $moderator = $this->createUser(['ROLE_MODERATOR']);

        $this->client->loginUser($moderator);
        $crawler = $this->client->request('GET', '/back/reports/');
        $ignoreForm = $crawler->filter('button[formaction$="/back/reports/' . $handled . '/ignore"]')->form();
        $this->client->submit($ignoreForm);
        $this->assertCount(1, $this->reviewed($reporter));
        // même formulaire renvoyé : déjà traité
        $this->client->submit($ignoreForm);
        $this->assertCount(1, $this->reviewed($reporter));

        // la cible disparaît avant que le signalement soit traité
        $this->em->clear();
        $this->em->remove($this->em->find(Memory::class, $goneId));
        $this->em->flush();
        $this->act('ignore', $goneReport);
        $this->assertSame([], $this->reviewed($late));
    }

    public function testSansAuteurDeSignalementRienNEstCree(): void
    {
        $report = new Report(ReportTargetType::COMMENT, 1, ReportReason::SPAM);
        static::getContainer()->get(ModerationNotifier::class)->reportReviewed($report);
        $this->assertFalse($this->em->getUnitOfWork()->hasPendingInsertions());
    }

    public function testAcquittementEtPurge(): void
    {
        $reporter = $this->createUser();
        $comment = $this->comment($this->createMemory($this->createUser()), $this->createUser());
        $reportId = $this->report(ReportTargetType::COMMENT, $comment->getId(), $reporter);
        $headers = $this->authHeaders($reporter);

        $this->client->loginUser($this->createUser(['ROLE_MODERATOR']));
        $this->act('ignore', $reportId);

        $id = $this->reviewed($reporter)[0]['id'];
        $this->client->request('POST', '/api/secure/notifications/comments/' . $id . '/seen', server: $headers);
        $this->assertResponseIsSuccessful();
        $this->assertSame([], $this->reviewed($reporter));

        // vue depuis plus de 30 jours : purgée comme les autres
        $notification = $this->em->find(\App\Entity\Notification::class, $id);
        $notification->setSeenAt(new \DateTimeImmutable('-40 days'));
        $this->em->flush();
        $count = static::getContainer()->get(\App\Repository\NotificationRepository::class)->deleteSeenBefore(new \DateTimeImmutable('-30 days'));
        $this->assertGreaterThanOrEqual(1, $count);
    }
}
