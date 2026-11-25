<?php

namespace App\Service;

use App\Entity\Memory;
use App\Entity\Picture;
use App\Entity\User;
use App\Enum\PictureStatus;
use App\Exception\ApiResponseException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;

/**
 * Ajoute à un souvenir les fichiers `pictures[]` d'une requête avec leurs métadonnées `pictures_meta`.
 * VichUploader déplace les fichiers au flush uniquement.
 */
class MemoryPictureUploader
{
    public function __construct(
        private readonly ApiValidationErrorResponder $validationErrorResponder,
        private readonly PictureTypeValidator $pictureTypeValidator,
        private readonly PictureFileValidator $pictureFileValidator,
    ) {
    }

    /**
     * Les photos sont envoyées par l'auteur du souvenir : elles sont donc approuvées d'office, y compris la photo
     * actuelle (`after`). Lève une 422 si un fichier n'est pas une image valide, une 400 si un type est inconnu ou
     * s'il y a plus d'une photo `after`, une 409 si le souvenir en a déjà une approuvée. Rien n'est ajouté tant
     * que tous les envois ne sont pas validés.
     *
     * @param bool $appendPosition position par défaut = nombre de photos déjà présentes (modification) au lieu de l'index
     *
     * @throws ApiResponseException
     */
    public function attach(Memory $memory, Request $request, User $user, bool $appendPosition = false): void
    {
        $pictureFiles = $request->files->get('pictures', []);
        $pictureFiles = is_array($pictureFiles) ? $pictureFiles : [];
        $rawMeta = $request->request->all()['pictures_meta'] ?? '[]';
        $picturesMeta = is_string($rawMeta) ? json_decode($rawMeta, true) : null;
        $picturesMeta = is_array($picturesMeta) ? $picturesMeta : [];

        // Contenu de chaque fichier (vraie image, 10 Mo, JPEG/PNG/GIF/WebP), puis type de chaque photo
        $fileErrors = [];
        foreach ($pictureFiles as $index => $uploadedFile) {
            if ($uploadedFile instanceof UploadedFile) {
                foreach ($this->pictureFileValidator->validate($uploadedFile) as $violation) {
                    $fileErrors['pictures.' . $index][] = $violation->getMessage();
                }
            }
        }
        if ($fileErrors) {
            throw new ApiResponseException($this->validationErrorResponder->createResponse($fileErrors));
        }

        $types = [];
        foreach ($pictureFiles as $index => $uploadedFile) {
            if ($uploadedFile instanceof UploadedFile) {
                $types[$index] = is_array($picturesMeta[$index] ?? null) ? ($picturesMeta[$index]['type'] ?? Picture::TYPE_OTHER) : Picture::TYPE_OTHER;
            }
        }
        if ($error = $this->pictureTypeValidator->validateNewTypes($memory, $types)) {
            throw new ApiResponseException($error);
        }

        foreach ($pictureFiles as $index => $uploadedFile) {
            if ($uploadedFile instanceof UploadedFile) {
                $picture = new Picture();
                $picture->setImageFile($uploadedFile);

                $meta = is_array($picturesMeta[$index] ?? null) ? $picturesMeta[$index] : [];
                $picture->setType($meta['type'] ?? Picture::TYPE_OTHER);
                $picture->setPosition($meta['position'] ?? ($appendPosition ? count($memory->getPictures()) : $index));
                $picture->setStatus(PictureStatus::APPROVED);
                $picture->setUploadedBy($user);

                if (isset($meta['credit'])) {
                    $picture->setCopyrightHolder($meta['credit']);
                }

                $memory->addPicture($picture);
            }
        }
    }
}
