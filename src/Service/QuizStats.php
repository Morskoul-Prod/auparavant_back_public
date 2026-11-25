<?php

namespace App\Service;

use App\Entity\User;
use App\Repository\QuizGameRepository;

/**
 * Série et statistiques personnelles d'un joueur (calculées à la volée, aucun compteur stocké sur User).
 */
class QuizStats
{
    public function __construct(
        private readonly QuizGameRepository $games,
        private readonly QuizStreakCalculator $streaks,
        private readonly QuizClock $clock,
    ) {
    }

    /** @return array{current: int, best: int} */
    public function streak(User $user): array
    {
        $days = $this->games->findFinishedDays($user);

        return ['current' => $this->streaks->current($days, $this->clock->today()), 'best' => $this->streaks->best($days)];
    }

    /** @return array{currentStreak: int, bestStreak: int, gamesPlayed: int, bestScore: int, averageScore: float} */
    public function summary(User $user): array
    {
        $streak = $this->streak($user);

        return ['currentStreak' => $streak['current'], 'bestStreak' => $streak['best']] + $this->games->summarize($user);
    }
}
