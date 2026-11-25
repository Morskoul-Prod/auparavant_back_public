<?php

namespace App\Enum;

/**
 * Visibilité d'un commentaire : pas de modération a priori, un commentaire signalé peut être masqué ensuite.
 */
enum CommentStatus: string
{
    case VISIBLE = 'visible';
    case HIDDEN = 'hidden';

    public function getLabel(): string
    {
        return match ($this) {
            self::VISIBLE => 'Visible',
            self::HIDDEN => 'Masqué',
        };
    }
}
