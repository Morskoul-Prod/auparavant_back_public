<?php

namespace App\Enum;

/**
 * Motif d'un signalement, choisi par l'utilisateur
 */
enum ReportReason: string
{
    case SPAM = 'spam';
    case ILLEGAL = 'illegal';
    case OFFENSIVE = 'offensive';
    case COPYRIGHT = 'copyright';
    case OTHER = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::SPAM => 'Spam',
            self::ILLEGAL => 'Contenu illégal',
            self::OFFENSIVE => 'Contenu offensant',
            self::COPYRIGHT => 'Droit d\'auteur',
            self::OTHER => 'Autre',
        };
    }
}
