<?php

namespace App\Service;

use App\Entity\Memory;
use App\Entity\Picture;
use App\Entity\User;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Règles du retrait de photos par l'auteur d'un souvenir (`delete_pictures` à la modification) :
 * - il ne retire que les photos qu'il a envoyées ; celles d'un autre utilisateur (par exemple une photo
 *   actuelle proposée par un contributeur) relèvent de la modération (403) ;
 * - il ne peut pas retirer la dernière photo « avant » du souvenir, ni sa dernière photo « avant » ou « autre »
 *   (la galerie : une photo « actuelle » seule n'est affichée nulle part), sauf s'il en envoie une autre dans
 *   la même modification (422).
 */
class PictureRemovalValidator
{
    /**
     * @param iterable<Picture> $toRemove
     */
    public function forbiddenFor(User $user, iterable $toRemove): ?JsonResponse
    {
        foreach ($toRemove as $picture) {
            // Sans déposant connu (photo antérieure à la modération, compte supprimé) : photo du souvenir, donc de son auteur
            $uploader = $picture->getUploadedBy();
            if ($uploader !== null && $uploader !== $user) {
                return new JsonResponse(
                    ['error' => 'Vous ne pouvez retirer que les photos que vous avez envoyées.'],
                    Response::HTTP_FORBIDDEN
                );
            }
        }

        return null;
    }

    /**
     * À appeler après le retrait et l'ajout des nouvelles photos : si une photo « avant » a été retirée, le souvenir
     * doit en garder une approuvée ; si une photo de la galerie (« avant » ou « autre ») a été retirée, il doit garder
     * au moins une photo approuvée de la galerie (sans elle, la photo actuelle seule ne serait affichée nulle part).
     *
     * @param iterable<Picture> $removed
     */
    public function lastPictureError(Memory $memory, iterable $removed): ?JsonResponse
    {
        $removedBefore = false;
        $removedGallery = false;
        foreach ($removed as $picture) {
            $removedBefore = $removedBefore || $picture->isBefore();
            $removedGallery = $removedGallery || !$picture->isAfter();
        }

        $hasBefore = false;
        $hasGallery = false;
        foreach ($memory->getPictures() as $picture) {
            if ($picture->isApproved()) {
                $hasBefore = $hasBefore || $picture->isBefore();
                $hasGallery = $hasGallery || !$picture->isAfter();
            }
        }

        if ($removedBefore && !$hasBefore) {
            return $this->invalid('Un souvenir doit garder au moins une photo « avant » : ajoutez-en une autre avant de retirer celle-ci.');
        }
        if ($removedGallery && !$hasGallery) {
            return $this->invalid('Un souvenir doit garder au moins une photo « avant » ou « autre » : ajoutez-en une autre avant de retirer celle-ci.');
        }

        return null;
    }

    private function invalid(string $message): JsonResponse
    {
        return new JsonResponse([
            'error' => 'Données invalides',
            'errors' => ['delete_pictures' => [$message]],
        ], Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
