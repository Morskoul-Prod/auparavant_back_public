<?php

namespace App\Dto\Memory;

/**
 * Création d'un souvenir sur une localisation existante (`POST /api/secure/create/memory-and-place`).
 * Le lieu est soit un lieu existant (`placeId`), soit un nouveau lieu (`newPlace`).
 */
final class CreateMemoryRequest
{
    public function __construct(
        public readonly int|float|string $locationId,
        public readonly ?string $addressId,
        public readonly int|float|string|null $placeId,
        public readonly ?NewPlace $newPlace,
        public readonly MemoryContent $memory,
    ) {
    }
}
