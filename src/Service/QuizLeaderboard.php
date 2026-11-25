<?php

namespace App\Service;

use App\Entity\QuizGame;
use App\Entity\User;
use App\Enum\QuizGameStatus;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Classements du quiz, sur les parties terminées uniquement. Un compte supprimé emporte ses parties (CASCADE).
 * Seuls `id` et `displayName` des joueurs sont exposés.
 *
 * - score : meilleur score du joueur sur la période (day = aujourd'hui, week = 7 jours glissants aujourd'hui compris,
 *   all = depuis toujours). Égalité de score = même rang ; à rang égal, la partie terminée la plus tôt passe devant.
 * - streak : meilleure série de jours consécutifs (toute la période : `period` ne s'applique pas), départagée par la
 *   série en cours. Calculée à la volée sur les jours de parties terminées de tous les joueurs ; si la table grossit
 *   beaucoup, il faudra la dénormaliser (voir taches.todo).
 */
class QuizLeaderboard
{
    public const PERIODS = ['day', 'week', 'all'];
    public const TYPES = ['score', 'streak'];
    public const DEFAULT_LIMIT = 20;
    public const MAX_LIMIT = 100;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $users,
        private readonly QuizStreakCalculator $streaks,
        private readonly QuizClock $clock,
    ) {
    }

    /**
     * @return array{type: string, period: string, limit: int, entries: list<array<string, mixed>>, me: array<string, mixed>|null}
     */
    public function build(string $type, string $period, int $limit, ?User $viewer): array
    {
        $limit = max(1, min(self::MAX_LIMIT, $limit));
        $data = $type === 'streak' ? $this->byStreak($limit, $viewer) : $this->byScore($period, $limit, $viewer);

        return ['type' => $type, 'period' => $period, 'limit' => $limit] + $data;
    }

    /** @return array{entries: list<array<string, mixed>>, me: array<string, mixed>|null} */
    private function byScore(string $period, int $limit, ?User $viewer): array
    {
        $rows = $this->scoreQuery($period)
            ->select('IDENTITY(g.user) AS userId', 'MAX(g.totalScore) AS score', 'COUNT(g.id) AS gamesPlayed', 'MIN(g.finishedAt) AS firstFinishedAt')
            ->groupBy('g.user')
            ->orderBy('score', 'DESC')
            ->addOrderBy('firstFinishedAt', 'ASC')
            ->addOrderBy('userId', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult();

        $users = $this->usersById(array_map(fn (array $r) => (int) $r['userId'], $rows));
        $entries = [];
        $rank = 0;
        $previousScore = null;
        foreach ($rows as $index => $row) {
            $score = (int) $row['score'];
            if ($score !== $previousScore) {
                $rank = $index + 1;
                $previousScore = $score;
            }
            $entries[] = [
                'rank' => $rank,
                'user' => $this->publicUser($users[(int) $row['userId']] ?? null),
                'score' => $score,
                'gamesPlayed' => (int) $row['gamesPlayed'],
            ];
        }

        $me = null;
        if ($viewer) {
            $mine = $this->scoreQuery($period)
                ->select('MAX(g.totalScore) AS score', 'COUNT(g.id) AS gamesPlayed')
                ->andWhere('g.user = :viewer')
                ->setParameter('viewer', $viewer)
                ->getQuery()
                ->getSingleResult();
            if ($mine['gamesPlayed'] > 0) {
                $ahead = (int) $this->scoreQuery($period)
                    ->select('COUNT(DISTINCT g.user)')
                    ->andWhere('g.totalScore > :mine')
                    ->setParameter('mine', (int) $mine['score'])
                    ->getQuery()
                    ->getSingleScalarResult();
                $me = ['rank' => $ahead + 1, 'score' => (int) $mine['score'], 'gamesPlayed' => (int) $mine['gamesPlayed']];
            }
        }

        return ['entries' => $entries, 'me' => $me];
    }

    /** Parties terminées de la période (jour de Paris) */
    private function scoreQuery(string $period): \Doctrine\ORM\QueryBuilder
    {
        $qb = $this->em->createQueryBuilder()
            ->from(QuizGame::class, 'g')
            ->where('g.status = :finished')
            ->setParameter('finished', QuizGameStatus::FINISHED);

        $today = $this->clock->today();
        if ($period === 'day') {
            $qb->andWhere('g.date = :from')->setParameter('from', $today, 'date_immutable');
        } elseif ($period === 'week') {
            $qb->andWhere('g.date >= :from')->setParameter('from', $today->modify('-6 days'), 'date_immutable');
        }

        return $qb;
    }

    /** @return array{entries: list<array<string, mixed>>, me: array<string, mixed>|null} */
    private function byStreak(int $limit, ?User $viewer): array
    {
        $daysByUser = [];
        $rows = $this->em->createQueryBuilder()
            ->select('IDENTITY(g.user) AS userId', 'g.date AS day')
            ->from(QuizGame::class, 'g')
            ->where('g.status = :finished')
            ->setParameter('finished', QuizGameStatus::FINISHED)
            ->getQuery()
            ->getArrayResult();
        foreach ($rows as $row) {
            $daysByUser[(int) $row['userId']][] = $row['day'];
        }

        $today = $this->clock->today();
        $ranking = [];
        foreach ($daysByUser as $userId => $days) {
            $ranking[] = ['userId' => $userId, 'streak' => $this->streaks->best($days), 'currentStreak' => $this->streaks->current($days, $today)];
        }
        usort($ranking, fn (array $a, array $b) => [$b['streak'], $b['currentStreak'], $a['userId']] <=> [$a['streak'], $a['currentStreak'], $b['userId']]);

        $ranked = [];
        $rank = 0;
        $previous = null;
        foreach ($ranking as $index => $row) {
            if ($row['streak'] !== $previous) {
                $rank = $index + 1;
                $previous = $row['streak'];
            }
            $ranked[] = ['rank' => $rank] + $row;
        }

        $top = array_slice($ranked, 0, $limit);
        $users = $this->usersById(array_column($top, 'userId'));
        $entries = array_map(fn (array $row) => [
            'rank' => $row['rank'],
            'user' => $this->publicUser($users[$row['userId']] ?? null),
            'streak' => $row['streak'],
            'currentStreak' => $row['currentStreak'],
        ], $top);

        $me = null;
        if ($viewer) {
            foreach ($ranked as $row) {
                if ($row['userId'] === $viewer->getId()) {
                    $me = ['rank' => $row['rank'], 'streak' => $row['streak'], 'currentStreak' => $row['currentStreak']];
                    break;
                }
            }
        }

        return ['entries' => $entries, 'me' => $me];
    }

    /**
     * @param list<int> $ids
     * @return array<int, User>
     */
    private function usersById(array $ids): array
    {
        $users = [];
        foreach ($ids ? $this->users->findBy(['id' => $ids]) : [] as $user) {
            $users[$user->getId()] = $user;
        }

        return $users;
    }

    /** @return array{id: int|null, displayName: string} */
    private function publicUser(?User $user): array
    {
        return ['id' => $user?->getId(), 'displayName' => $user?->getDisplayName() ?? 'Utilisateur supprimé'];
    }
}
