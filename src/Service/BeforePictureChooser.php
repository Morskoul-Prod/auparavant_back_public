<?php

namespace App\Service;

use App\Entity\Memory;
use App\Entity\Picture;

/**
 * Choix de la photo « avant » d'un souvenir, celle du comparateur avant/après. Un souvenir n'en a qu'une :
 * en choisir une repasse les autres photos « avant » en « autre ».
 */
class BeforePictureChooser
{
    /**
     * Photo de la galerie du souvenir pouvant servir d'« avant » : approuvée et pas la photo actuelle.
     * Accepte un entier ou une chaîne numérique (champ multipart) ; null si elle n'existe pas ou ne convient pas.
     */
    public function find(Memory $memory, mixed $id): ?Picture
    {
        if (is_string($id) && ctype_digit($id)) {
            $id = (int) $id;
        }
        if (!is_int($id)) {
            return null;
        }

        foreach ($memory->getPictures() as $picture) {
            if ($picture->getId() === $id && $picture->isApproved() && !$picture->isAfter()) {
                return $picture;
            }
        }

        return null;
    }

    public function hasBefore(Memory $memory): bool
    {
        foreach ($memory->getPictures() as $picture) {
            if ($picture->isBefore() && $picture->isApproved()) {
                return true;
            }
        }

        return false;
    }

    /** La photo choisie devient la photo « avant » ; les autres photos « avant » approuvées repassent en « autre ». */
    public function apply(Memory $memory, Picture $chosen): void
    {
        foreach ($memory->getPictures() as $picture) {
            if ($picture !== $chosen && $picture->isBefore() && $picture->isApproved()) {
                $picture->setType(Picture::TYPE_OTHER);
            }
        }
        $chosen->setType(Picture::TYPE_BEFORE);
    }

    /**
     * À l'approbation d'une photo actuelle : applique la photo « avant » qu'elle suggérait, si le souvenir n'en a
     * toujours pas et si la photo suggérée est encore valable. La suggestion est effacée dans tous les cas.
     */
    public function applySuggestion(Picture $after): void
    {
        $suggested = $after->getSuggestedBefore();
        $memory = $after->getMemory();
        $after->setSuggestedBefore(null);

        if ($suggested === null || $memory === null || $suggested->getMemory() !== $memory) {
            return;
        }
        if ($suggested->isApproved() && !$suggested->isAfter() && !$this->hasBefore($memory)) {
            $this->apply($memory, $suggested);
        }
    }

    public function errorMessage(): string
    {
        return 'Choisissez une photo « avant » parmi les photos approuvées de ce souvenir (hors photo actuelle).';
    }
}
