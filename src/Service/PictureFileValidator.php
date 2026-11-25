<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Règles sur le fichier d'une photo envoyée à l'API (mêmes que le formulaire d'image du back-office) :
 * 10 Mo au plus (les photos de téléphone dépassent vite 5 Mo), JPEG, PNG, GIF ou WebP. Le type est lu dans le contenu du fichier, pas dans son nom,
 * et le fichier doit être une vraie image.
 */
class PictureFileValidator
{
    public function __construct(private readonly ValidatorInterface $validator)
    {
    }

    public function validate(UploadedFile $file): ConstraintViolationListInterface
    {
        return $this->validator->validate($file, new Assert\Image(
            maxSize: '10M',
            mimeTypes: ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
            maxSizeMessage: 'L\'image est trop volumineuse ({{ size }} {{ suffix }}). Taille maximale : {{ limit }} {{ suffix }}.',
            mimeTypesMessage: 'Veuillez choisir une image valide (JPEG, PNG, GIF ou WebP)',
        ));
    }
}
