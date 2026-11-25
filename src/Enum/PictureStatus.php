<?php

namespace App\Enum;

/**
 * Statut de modération d'une photo.
 * Seules les photos approuvées sont exposées publiquement.
 */
enum PictureStatus: string
{
    case PENDING = 'pending';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';

    public function getLabel(): string
    {
        return match ($this) {
            self::PENDING => 'En attente',
            self::APPROVED => 'Approuvée',
            self::REJECTED => 'Rejetée',
        };
    }
}
