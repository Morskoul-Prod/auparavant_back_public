<?php

namespace App\Tests\Api;

use App\Entity\QuizGame;
use App\Enum\QuizGameStatus;
use App\Tests\Support\QuizTestCase;

class QuizLeaderboardTest extends QuizTestCase
{
    private function leaderboard(string $query = '', $viewer = null): array
    {
        $this->client->request('GET', '/api/quiz/leaderboard' . $query, server: $viewer ? $this->authHeaders($viewer) : []);
        $this->assertResponseIsSuccessful();

        return $this->json();
    }

    private function scores(array $board): array
    {
        return array_map(fn ($e) => $e['score'], $board['entries']);
    }

    public function testClassementDuJourPartiesTermineesUniquement(): void
    {
        [$a, $b, $c, $d] = [$this->createUser(), $this->createUser(), $this->createUser(), $this->createUser()];
        $this->createGame($a, 0, QuizGameStatus::FINISHED, 30000);
        $this->createGame($b, 0, QuizGameStatus::FINISHED, 42000);
        $this->createGame($c, 0, QuizGameStatus::IN_PROGRESS, 49000); // pas terminée : exclue
        $this->createGame($d, -1, QuizGameStatus::FINISHED, 50000);   // hier : exclue du jour

        $board = $this->leaderboard('?period=day');

        $this->assertSame('score', $board['type']);
        $this->assertSame('day', $board['period']);
        $this->assertSame([42000, 30000], $this->scores($board));
        $this->assertSame([1, 2], array_column($board['entries'], 'rank'));
        $this->assertSame($b->getId(), $board['entries'][0]['user']['id']);
        $this->assertNull($board['me'], 'anonyme : pas de rang');
    }

    public function testParDefautLeClassementEstCeluiDuJour(): void
    {
        $user = $this->createUser();
        $this->createGame($user, -2, QuizGameStatus::FINISHED, 40000);

        $this->assertSame([], $this->leaderboard()['entries']);
    }

    public function testPeriodeSemaineSeptJoursGlissants(): void
    {
        [$today, $sixDays, $sevenDays] = [$this->createUser(), $this->createUser(), $this->createUser()];
        $this->createGame($today, 0, QuizGameStatus::FINISHED, 10000);
        $this->createGame($sixDays, -6, QuizGameStatus::FINISHED, 20000);   // dernier jour inclus
        $this->createGame($sevenDays, -7, QuizGameStatus::FINISHED, 50000); // trop ancien

        $board = $this->leaderboard('?period=week');

        $this->assertSame([20000, 10000], $this->scores($board));
        $this->assertNotContains($sevenDays->getId(), array_map(fn ($e) => $e['user']['id'], $board['entries']));
    }

    public function testPeriodeToutesDatesMeilleurScoreParJoueur(): void
    {
        [$a, $b] = [$this->createUser(), $this->createUser()];
        $this->createGame($a, -30, QuizGameStatus::FINISHED, 45000);
        $this->createGame($a, -3, QuizGameStatus::FINISHED, 20000);
        $this->createGame($a, 0, QuizGameStatus::FINISHED, 31000);
        $this->createGame($b, -10, QuizGameStatus::FINISHED, 40000);

        $board = $this->leaderboard('?period=all');

        $this->assertCount(2, $board['entries'], 'une ligne par joueur');
        $this->assertSame([45000, 40000], $this->scores($board));
        $this->assertSame(3, $board['entries'][0]['gamesPlayed']);
    }

    public function testEgaliteMemeRang(): void
    {
        [$a, $b, $c] = [$this->createUser(), $this->createUser(), $this->createUser()];
        $this->createGame($a, 0, QuizGameStatus::FINISHED, 40000);
        $this->createGame($b, 0, QuizGameStatus::FINISHED, 40000);
        $this->createGame($c, 0, QuizGameStatus::FINISHED, 20000);

        $this->assertSame([1, 1, 3], array_column($this->leaderboard('?period=day')['entries'], 'rank'));
    }

    public function testLimiteParDefautEtMaximum(): void
    {
        $users = array_map(fn () => $this->createUser(), range(1, 22));
        foreach ($users as $i => $user) {
            $this->createGame($user, 0, QuizGameStatus::FINISHED, 1000 + $i * 10);
        }

        $default = $this->leaderboard('?period=day');
        $this->assertSame(20, $default['limit']);
        $this->assertCount(20, $default['entries']);
        $this->assertSame(1210, $default['entries'][0]['score']);

        $this->assertCount(3, $this->leaderboard('?period=day&limit=3')['entries']);
        $this->assertSame(100, $this->leaderboard('?period=day&limit=5000')['limit'], 'plafonné à 100');
        $this->assertCount(22, $this->leaderboard('?period=day&limit=5000')['entries']);
        $this->assertSame(1, $this->leaderboard('?period=day&limit=0')['limit'], 'au moins une ligne');
        $this->assertSame(20, $this->leaderboard('?period=day&limit=abc')['limit']);
    }

    public function testRangDeLUtilisateurConnecteMemeHorsDuPalmares(): void
    {
        $users = array_map(fn () => $this->createUser(), range(1, 5));
        foreach ($users as $i => $user) {
            $this->createGame($user, 0, QuizGameStatus::FINISHED, 50000 - $i * 1000);
        }
        $last = $users[4];

        $board = $this->leaderboard('?period=day&limit=2', $last);

        $this->assertCount(2, $board['entries']);
        $this->assertSame(['rank' => 5, 'score' => 46000, 'gamesPlayed' => 1], $board['me']);
        // Sans partie terminée dans la période : pas de rang
        $newcomer = $this->createUser();
        $this->assertNull($this->leaderboard('?period=day', $newcomer)['me']);
    }

    public function testRienDePersonnelNEstExpose(): void
    {
        $user = $this->createUser(['ROLE_USER', 'ROLE_ADMIN']);
        $this->createGame($user, 0, QuizGameStatus::FINISHED, 12345);

        $board = $this->leaderboard('?period=day', $user);

        $this->assertSame(['id', 'displayName'], array_keys($board['entries'][0]['user']));
        $raw = $this->client->getResponse()->getContent();
        $this->assertStringNotContainsString('@test.local', $raw);
        $this->assertStringNotContainsString('ROLE_', $raw);
        $this->assertStringNotContainsString('lastname', $raw);
        $this->assertStringNotContainsString('email', $raw);
    }

    public function testUtilisateurSupprimeDisparaitDuClassement(): void
    {
        [$gone, $stays] = [$this->createUser(), $this->createUser()];
        $this->createGame($gone, 0, QuizGameStatus::FINISHED, 50000);
        $this->createGame($stays, 0, QuizGameStatus::FINISHED, 10000);
        $goneId = $gone->getId();

        $this->deleteAccount($gone);

        foreach (['day', 'week', 'all'] as $period) {
            $board = $this->leaderboard('?period=' . $period);
            $this->assertSame([10000], $this->scores($board), $period);
            $this->assertSame([1], array_column($board['entries'], 'rank'));
        }
        $this->assertSame([$stays->getId()], array_map(fn ($e) => $e['user']['id'], $this->leaderboard('?type=streak')['entries']));
        $this->assertNull($this->em->getRepository(QuizGame::class)->findOneBy(['user' => $goneId]));
    }

    public function testClassementParSerie(): void
    {
        [$long, $current, $single] = [$this->createUser(), $this->createUser(), $this->createUser()];
        foreach ([-9, -8, -7, -6] as $offset) {
            $this->createGame($long, $offset); // 4 jours consécutifs, plus d'actualité
        }
        foreach ([-2, -1, 0] as $offset) {
            $this->createGame($current, $offset); // 3 jours, série en cours
        }
        $this->createGame($single, -20);
        $this->createGame($single, 0, QuizGameStatus::IN_PROGRESS); // ne compte pas

        $board = $this->leaderboard('?type=streak&period=day', $current);

        $this->assertSame('streak', $board['type']);
        $this->assertSame([4, 3, 1], array_column($board['entries'], 'streak'), 'meilleure série, period ignoré');
        $this->assertSame([0, 3, 0], array_column($board['entries'], 'currentStreak'));
        $this->assertSame($long->getId(), $board['entries'][0]['user']['id']);
        $this->assertSame(['id', 'displayName'], array_keys($board['entries'][0]['user']));
        $this->assertSame(['rank' => 2, 'streak' => 3, 'currentStreak' => 3], $board['me']);
    }

    public function testParametresInvalides(): void
    {
        $this->client->request('GET', '/api/quiz/leaderboard?period=month');
        $this->assertResponseStatusCodeSame(422);
        $this->assertArrayHasKey('query.period', $this->json()['errors']);

        $this->client->request('GET', '/api/quiz/leaderboard?type=nope');
        $this->assertResponseStatusCodeSame(422);
        $this->assertArrayHasKey('query.type', $this->json()['errors']);
    }
}
