<?php

namespace App\Dto\Memory;

use App\Enum\PlaceType;

/** Lieu à créer (nom et type) */
final class NewPlace
{
    public function __construct(
        public readonly string $name,
        public readonly PlaceType $type,
    ) {
    }
}
