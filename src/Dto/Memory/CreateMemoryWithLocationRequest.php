<?php

namespace App\Dto\Memory;

/** Création d'un souvenir avec sa localisation et son lieu (`POST /api/secure/create/memory-and-location-and-place`) */
final class CreateMemoryWithLocationRequest
{
    public function __construct(
        public readonly NewLocation $location,
        public readonly NewPlace $place,
        public readonly MemoryContent $memory,
    ) {
    }
}
