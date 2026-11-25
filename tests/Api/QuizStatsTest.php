<?php

namespace App\Tests\Api;

use App\Enum\QuizGameStatus;
use App\Tests\Support\QuizTestCase;

class QuizStatsTest extends QuizTestCase
{
    private function stats($user): array
    {
        $this->client->request('GET', '/api/secure/quiz/me', server: $this->authHeaders($user));
        $this->assertResponseIsSuccessful();

        return $this->json();
    }

    public function testStatistiquesSansPartie(): void
    {
        $this->assertSame(
            ['currentStreak' => 0, 'bestStreak' => 0, 'gamesPlayed' => 0, 'bestScore' => 0, 'averageScore' => 0],
            $this->stats($this->createUser()),
        );
    }

    public function testStatistiquesPersonnelles(): void
    {
        $user = $this->createUser();
        $other = $this->createUser();
        $this->createGame($user, -10, QuizGameStatus::FINISHED, 20000);
        $this->createGame($user, -9, QuizGameStatus::FINISHED, 30000);
        $this->createGame($user, -8, QuizGameStatus::FINISHED, 40000);
        $this->createGame($user, -1, QuizGameStatus::FINISHED, 25001);
        $this->createGame($user, -2, QuizGameStatus::IN_PROGRESS, 49000); // abandonnée : ne compte pas
        $this->createGame($other, 0, QuizGameStatus::FINISHED, 50000);

        $stats = $this->stats($user);

        $this->assertSame(1, $stats['currentStreak'], 'aujourd\'hui non joué : la série d\'hier reste valide');
        $this->assertSame(3, $stats['bestStreak']);
        $this->assertSame(4, $stats['gamesPlayed']);
        $this->assertSame(40000, $stats['bestScore']);
        $this->assertEqualsWithDelta(28750.3, $stats['averageScore'], 0.05);
    }

    public function testSerieEnCoursAvecEtSansAujourdHui(): void
    {
        $this->createEligibleMemories(5);
        $user = $this->createUser();
        $this->createGame($user, -2);
        $this->createGame($user, -1);

        $this->assertSame(['current' => 2, 'best' => 2], $this->daily($user)['streak'], 'série d\'hier conservée');

        $this->playPerfectGame($user);
        $this->assertSame(['current' => 3, 'best' => 3], $this->json()['streak'], 'aujourd\'hui terminé : la série s\'allonge');
        $this->assertSame(3, $this->stats($user)['currentStreak']);
    }

    public function testSerieCasseeApresUnJourSansPartieTerminee(): void
    {
        $user = $this->createUser();
        $this->createGame($user, -4);
        $this->createGame($user, -3);
        // -2 manquant, -1 commencé mais jamais terminé : la série est tombée
        $this->createGame($user, -1, QuizGameStatus::IN_PROGRESS);

        $stats = $this->stats($user);
        $this->assertSame(0, $stats['currentStreak']);
        $this->assertSame(2, $stats['bestStreak']);
    }

    public function testUnTrouDansLHistorique(): void
    {
        $user = $this->createUser();
        foreach ([-6, -5, -4, -2, -1, 0] as $offset) {
            $this->createGame($user, $offset);
        }

        $stats = $this->stats($user);
        $this->assertSame(3, $stats['currentStreak']);
        $this->assertSame(3, $stats['bestStreak']);
    }
}
