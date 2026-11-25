<?php

namespace App\Service;

use App\Entity\QuizGame;
use App\Entity\QuizRound;

/**
 * Format JSON du quiz.
 *
 * Anti-triche : une manche non répondue ne se présente que par `question()` (numéro et photo). Rien d'autre
 * (titre, lieu, année, id du souvenir) ne sort avant la réponse, et la photo passe par une URL signée du quiz
 * (voir QuizPictures) plutôt que par l'URL publique du fichier ; `result()` n'accepte que des manches répondues.
 */
class QuizPresenter
{
    public function __construct(private readonly QuizPictures $pictures)
    {
    }

    /** Manche en attente de réponse : numéro et photo uniquement */
    public function question(QuizRound $round): array
    {
        return [
            'position' => $round->getPosition(),
            'totalRounds' => QuizGame::ROUNDS_PER_GAME,
            'pictureUrl' => $this->pictures->url($round),
        ];
    }

    /** Correction d'une manche répondue : vérité, réponse, souvenir et détail du score */
    public function result(QuizRound $round): array
    {
        if (!$round->isAnswered()) {
            throw new \LogicException('La correction d\'une manche non répondue ne doit jamais être exposée.');
        }

        return [
            'position' => $round->getPosition(),
            'pictureUrl' => $this->pictures->url($round),
            'truth' => ['lat' => $round->getTrueLat(), 'lng' => $round->getTrueLng(), 'year' => $round->getTrueYear()],
            'answer' => ['lat' => $round->getAnswerLat(), 'lng' => $round->getAnswerLng(), 'year' => $round->getAnswerYear()],
            // Titre figé à la création de la partie ; id null si le souvenir a été supprimé depuis
            'memory' => ['id' => $round->getMemory()?->getId(), 'title' => $round->getMemoryTitle()],
            'distanceKm' => $round->getDistanceKm(),
            'yearDiff' => $round->getYearDiff(),
            'locationScore' => $round->getLocationScore(),
            'yearScore' => $round->getYearScore(),
            'score' => $round->getScore(),
        ];
    }

    /**
     * @param array{current: int, best: int} $streak
     */
    public function state(?QuizGame $game, \DateTimeImmutable $today, array $streak): array
    {
        $current = $game && !$game->isFinished() ? $game->getCurrentRound() : null;

        return [
            'date' => $today->format('Y-m-d'),
            'status' => $game?->getStatus()->value ?? 'not_started',
            'totalRounds' => QuizGame::ROUNDS_PER_GAME,
            'maxScore' => QuizScorer::MAX_GAME_SCORE,
            'score' => $game?->getTotalScore(),
            'currentRound' => $current ? $this->question($current) : null,
            'rounds' => $game ? array_map($this->result(...), $game->getAnsweredRounds()) : [],
            'streak' => $streak,
        ];
    }

    /** Récapitulatif d'une partie terminée */
    public function total(QuizGame $game): array
    {
        return [
            'score' => $game->getTotalScore(),
            'maxScore' => QuizScorer::MAX_GAME_SCORE,
            'rounds' => array_map($this->result(...), $game->getAnsweredRounds()),
        ];
    }
}
