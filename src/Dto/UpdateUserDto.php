<?php

namespace App\Dto;

use App\Validator\ReservedPseudonym;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Seuls champs modifiables d'un compte via l'API : le pseudonyme (mêmes règles qu'à l'inscription).
 */
class UpdateUserDto
{
    /** Champs acceptés dans le corps de la requête */
    public const FIELDS = ['pseudonym'];

    #[Assert\NotBlank(message: 'Le pseudonyme est obligatoire.')]
    #[Assert\Length(
        min: 2,
        max: 30,
        minMessage: 'Votre pseudonyme doit comporter au moins {{ limit }} caractères.',
        maxMessage: 'Votre pseudonyme ne peut pas dépasser {{ limit }} caractères.',
    )]
    #[ReservedPseudonym]
    public ?string $pseudonym = null;
}
