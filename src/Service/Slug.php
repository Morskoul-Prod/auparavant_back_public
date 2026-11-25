<?php

namespace App\Service;

use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Adresses lisibles : /souvenirs/42-le-pont-de-pierre-rennes et /lieux/rennes.
 *
 * L'identifiant reste la seule référence d'un souvenir : le texte qui suit n'est que décoratif
 * (un titre modifié ne casse pas les liens déjà partagés ; la page de partage redirige vers l'adresse à jour).
 * Le front garde une copie de cet algorithme en repli (src/utils/slug.ts) : les deux doivent rester alignés.
 */
final class Slug
{
    private const MAX_LENGTH = 80;

    /** « Le Pont de Pierre ! » → « le-pont-de-pierre » (minuscules, sans accents ni ponctuation) */
    public static function of(?string $text): string
    {
        $slug = (new AsciiSlugger('fr'))
            ->slug((string) $text, '-')
            ->lower()
            ->toString();

        return rtrim(substr($slug, 0, self::MAX_LENGTH), '-');
    }

    /** Partie lisible de l'adresse d'un souvenir : titre puis ville (« le-pont-de-pierre-rennes ») */
    public static function ofMemory(?string $title, ?string $city): string
    {
        return self::of(trim(($title ?? '') . ' ' . ($city ?? '')));
    }

    /** Adresse d'un souvenir : « 42-le-pont-de-pierre-rennes » (juste « 42 » sans titre ni ville) */
    public static function memoryParam(int $id, ?string $title, ?string $city): string
    {
        $slug = self::ofMemory($title, $city);

        return $slug === '' ? (string) $id : $id . '-' . $slug;
    }
}
