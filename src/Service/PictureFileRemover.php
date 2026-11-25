<?php

namespace App\Service;

use App\Entity\Picture;
use Vich\UploaderBundle\Handler\UploadHandler;

/**
 * Supprime le fichier d'une photo refusée tout en conservant la ligne en base
 * (motif et notification). Après appel, imageName et imageUrl valent null.
 */
class PictureFileRemover
{
    public function __construct(private readonly UploadHandler $uploadHandler)
    {
    }

    /**
     * Sans effet si la photo n'a déjà plus de fichier. Le flush reste à la charge de l'appelant.
     */
    public function remove(Picture $picture): void
    {
        $this->uploadHandler->remove($picture, 'imageFile');
    }
}
