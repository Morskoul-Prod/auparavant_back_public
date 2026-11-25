<?php

namespace App\Enum;

/**
 * Avancement d'une partie du quiz du jour.
 * Une partie `in_progress` dont le jour est passé est abandonnée : elle n'est plus reprenable et ne compte nulle part.
 */
enum QuizGameStatus: string
{
    case IN_PROGRESS = 'in_progress';
    case FINISHED = 'finished';

    public function getLabel(): string
    {
        return match ($this) {
            self::IN_PROGRESS => 'En cours',
            self::FINISHED => 'Terminée',
        };
    }
}
