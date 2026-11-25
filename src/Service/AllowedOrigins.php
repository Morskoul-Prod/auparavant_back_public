<?php

namespace App\Service;

/**
 * Origines du front autorisées (variable CORS_ALLOWED_ORIGINS) : partagées par les en-têtes CORS
 * et par le contrôle d'origine des routes qui s'appuient sur le cookie de refresh.
 */
final class AllowedOrigins
{
    /** @param list<string> $origins */
    public function __construct(private readonly array $origins)
    {
    }

    public function contains(string $origin): bool
    {
        return in_array($origin, $this->origins, true);
    }
}
