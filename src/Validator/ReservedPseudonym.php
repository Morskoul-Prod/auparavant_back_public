<?php

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * Refuse les pseudonymes qui se feraient passer pour l'équipe ou le système.
 *
 * Règle exacte : le pseudonyme est « normalisé » (minuscules, accents retirés, espaces, tirets et underscores
 * supprimés) puis comparé À L'IDENTIQUE à la liste des mots réservés. Seuls ces trois écarts sont donc ignorés :
 *  - refusés : « Admin », « ADMIN », « Modération », « moderateur », « Équipe », « a d m i n », « Aupa-ravant », « sys_tem » ;
 *  - acceptés : « adm1n » (un chiffre n'est pas un accent), « admin2 », « admins », « monadmin », « support-client »
 *    (le mot réservé doit être le pseudonyme entier, pas seulement une partie).
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class ReservedPseudonym extends Constraint
{
    /** Mots réservés, déjà normalisés */
    public const RESERVED = ['admin', 'administrateur', 'moderation', 'moderateur', 'support', 'auparavant', 'equipe', 'system'];

    public string $message = 'Ce pseudonyme est réservé, merci d\'en choisir un autre.';

    public static function normalize(string $value): string
    {
        $decomposed = \Normalizer::normalize($value, \Normalizer::FORM_D);
        $withoutAccents = preg_replace('/\p{Mn}+/u', '', $decomposed === false ? $value : $decomposed);

        return preg_replace('/[\s\-_]+/u', '', mb_strtolower($withoutAccents ?? $value));
    }
}
