<?php

namespace App\Enum;

/**
 * Avancement du traitement d'un signalement
 */
enum ReportStatus: string
{
    case OPEN = 'open';
    case HANDLED = 'handled';

    public function getLabel(): string
    {
        return match ($this) {
            self::OPEN => 'Ouvert',
            self::HANDLED => 'Traité',
        };
    }
}
