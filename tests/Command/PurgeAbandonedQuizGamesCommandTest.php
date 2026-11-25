<?php

namespace App\Tests\Command;

use App\Entity\QuizGame;
use App\Entity\QuizRound;
use App\Enum\QuizGameStatus;
use App\Tests\Support\QuizTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class PurgeAbandonedQuizGamesCommandTest extends QuizTestCase
{
    private function purge(array $options = []): CommandTester
    {
        $tester = new CommandTester((new Application(static::$kernel))->find('app:quiz:purge-abandoned'));
        $tester->execute($options);

        return $tester;
    }

    private function exists(int $id): bool
    {
        $this->em->clear();

        return $this->em->find(QuizGame::class, $id) !== null;
    }

    public function testSupprimeSeulementLesPartiesAbandonneesDePlusDe30Jours(): void
    {
        $abandonedOld = $this->createGame($this->createUser(), -31, QuizGameStatus::IN_PROGRESS)->getId();
        $abandonedRecent = $this->createGame($this->createUser(), -29, QuizGameStatus::IN_PROGRESS)->getId();
        $abandonedLimit = $this->createGame($this->createUser(), -30, QuizGameStatus::IN_PROGRESS)->getId();
        $finishedOld = $this->createGame($this->createUser(), -100, QuizGameStatus::FINISHED)->getId();
        $inProgressToday = $this->createGame($this->createUser(), 0, QuizGameStatus::IN_PROGRESS)->getId();

        $dry = $this->purge(['--dry-run' => true]);
        $this->assertStringContainsString('1 partie(s) abandonnée(s) à supprimer', $dry->getDisplay());
        $this->assertSame(5, $this->em->getRepository(QuizGame::class)->count([]));

        $this->assertSame(0, $this->purge()->getStatusCode());
        $this->assertFalse($this->exists($abandonedOld));
        foreach ([$abandonedRecent, $abandonedLimit, $finishedOld, $inProgressToday] as $id) {
            $this->assertTrue($this->exists($id), "partie $id conservée");
        }
    }

    public function testLesManchesPartentAvecLaPartie(): void
    {
        $user = $this->createUser();
        $this->createEligibleMemories(5);
        $this->startQuiz($user);
        $game = $this->em->getRepository(QuizGame::class)->findOneBy(['user' => $user->getId()]);
        $game->setDate($this->today(-40));
        $this->em->flush();
        $this->assertSame(5, $this->em->getRepository(QuizRound::class)->count([]));

        $this->purge();

        $this->em->clear();
        $this->assertSame(0, $this->em->getRepository(QuizRound::class)->count([]));
    }

    public function testOptionDays(): void
    {
        $id = $this->createGame($this->createUser(), -2, QuizGameStatus::IN_PROGRESS)->getId();
        $today = $this->createGame($this->createUser(), 0, QuizGameStatus::IN_PROGRESS)->getId();

        $this->purge(['--days' => '1']);
        $this->assertFalse($this->exists($id));
        $this->assertTrue($this->exists($today));

        $this->assertSame(2, $this->purge(['--days' => 'abc'])->getStatusCode());
        $this->assertSame(2, $this->purge(['--days' => '-1'])->getStatusCode());
    }
}
