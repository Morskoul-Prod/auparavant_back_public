<?php

namespace App\Validator;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Constraints\Compound;

/**
 * Politique de mot de passe unique : inscription et changement de mot de passe (API).
 * 6 caractères au moins (comme le formulaire du back-office), 4096 au plus (limite de Symfony pour le hachage).
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD | \Attribute::TARGET_PARAMETER)]
class PasswordPolicy extends Compound
{
    public const MIN_LENGTH = 6;

    protected function getConstraints(array $options): array
    {
        return [
            new Assert\NotBlank(message: 'Veuillez entrer un mot de passe.'),
            new Assert\Length(
                min: self::MIN_LENGTH,
                max: 4096,
                minMessage: 'Votre mot de passe doit contenir au moins {{ limit }} caractères.',
                maxMessage: 'Votre mot de passe est trop long.',
            ),
        ];
    }
}
