<?php

namespace App\Dto\Memory;

/** Localisation à créer, telle que choisie sur la carte */
final class NewLocation
{
    public function __construct(
        public readonly string $area,
        public readonly string $department,
        public readonly ?string $district,
        public readonly string $street,
        public readonly string $city,
        public readonly int|string $zipcode,
        public readonly int|float|string $latitude,
        public readonly int|float|string $longitude,
        public readonly ?string $addressId,
    ) {
    }
}
