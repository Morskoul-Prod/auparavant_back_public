<?php

namespace App\Dto\Memory;

use App\Enum\PlaceType;

/** Modification du nom et du type d'un lieu existant, demandée avec la modification d'un souvenir */
final class PlaceUpdate
{
    public function __construct(
        public readonly int|float|string $placeId,
        public readonly string $name,
        public readonly PlaceType $type,
    ) {
    }
}
