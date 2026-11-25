<?php

namespace App\Dto\Memory;

/**
 * Titre, texte et datation d'un souvenir, une fois le JSON `memory` contrôlé.
 * La datation est soit une date exacte (l'année en est déduite), soit une année seule.
 */
final class MemoryContent
{
    public function __construct(
        public readonly string $title,
        public readonly string $content,
        public readonly ?\DateTime $pictureDate,
        public readonly int $year,
    ) {
    }
}
