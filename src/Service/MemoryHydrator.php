<?php

namespace App\Service;

use App\Dto\Memory\MemoryContent;
use App\Entity\Memory;

/**
 * Applique titre, texte et datation (`MemoryContent`) à un souvenir, à la création comme à la modification.
 */
class MemoryHydrator
{
    public function apply(Memory $memory, MemoryContent $content): void
    {
        // setPictureDate(null) conserve l'année ; setYear est sans effet si une date est présente :
        // passer uniquement une année remet donc la date exacte à null (bascule date exacte -> année seule)
        $memory
            ->setTitle($content->title)
            ->setContent($content->content)
            ->setPictureDate($content->pictureDate)
            ->setYear($content->pictureDate ? null : $content->year);
    }
}
