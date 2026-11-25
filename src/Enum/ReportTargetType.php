<?php

namespace App\Enum;

/**
 * Type de contenu signalable. Seul `comment` est pris en charge pour l'instant (cf. ReportTargetResolver).
 */
enum ReportTargetType: string
{
    case MEMORY = 'memory';
    case PICTURE = 'picture';
    case COMMENT = 'comment';

    public function getLabel(): string
    {
        return match ($this) {
            self::MEMORY => 'Souvenir',
            self::PICTURE => 'Photo',
            self::COMMENT => 'Commentaire',
        };
    }
}
