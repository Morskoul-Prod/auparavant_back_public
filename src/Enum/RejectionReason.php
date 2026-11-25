<?php

namespace App\Enum;

/**
 * Motifs prédéfinis de refus d'une photo proposée, choisis par le modérateur
 */
enum RejectionReason: string
{
    case BLURRY = 'floue';
    case WRONG_PLACE = 'mauvais_lieu';
    case INAPPROPRIATE = 'inapproprie';
    case DUPLICATE = 'doublon';
    case OTHER = 'autre';

    public function getLabel(): string
    {
        return match ($this) {
            self::BLURRY => 'Photo floue ou illisible',
            self::WRONG_PLACE => 'Mauvais lieu',
            self::INAPPROPRIATE => 'Contenu inapproprié',
            self::DUPLICATE => 'Doublon',
            self::OTHER => 'Autre',
        };
    }

    /**
     * Choix pour le formulaire de rejet (label => valeur)
     *
     * @return array<string, string>
     */
    public static function getChoices(): array
    {
        $choices = [];
        foreach (self::cases() as $case) {
            $choices[$case->getLabel()] = $case->value;
        }

        return $choices;
    }
}
