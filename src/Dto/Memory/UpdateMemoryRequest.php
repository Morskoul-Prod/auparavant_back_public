<?php

namespace App\Dto\Memory;

/** Modification d'un souvenir (`POST /api/secure/update/memory-and-place/{id}`) */
final class UpdateMemoryRequest
{
    public function __construct(
        public readonly MemoryContent $memory,
        public readonly ?PlaceUpdate $placeUpdate,
    ) {
    }
}
