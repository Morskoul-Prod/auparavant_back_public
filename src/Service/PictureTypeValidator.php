<?php

namespace App\Service;

use App\Entity\Memory;
use App\Entity\Picture;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Règles sur le type des photos d'un souvenir : liste blanche des types
 * et au plus une photo actuelle (`after`) approuvée par souvenir.
 */
class PictureTypeValidator
{
    /**
     * Valide les types de photos sur le point d'être ajoutées à un souvenir.
     * Renvoie une 400 si un type est inconnu ou s'il y a plus d'une photo `after` dans l'envoi,
     * une 409 si le souvenir possède déjà une photo `after` approuvée.
     *
     * @param array<int|string, mixed> $types types demandés, indexés par l'index de la photo dans l'envoi
     */
    public function validateNewTypes(Memory $memory, array $types): ?JsonResponse
    {
        $afterCount = 0;
        foreach ($types as $index => $type) {
            if (!is_string($type) || !in_array($type, Picture::getAllowedTypes(), true)) {
                return $this->error(sprintf(
                    'Type de photo invalide : pictures_meta[%s].type doit valoir %s.',
                    $index,
                    implode(', ', Picture::getAllowedTypes())
                ), Response::HTTP_BAD_REQUEST);
            }
            if ($type === Picture::TYPE_AFTER) {
                ++$afterCount;
            }
        }

        if ($afterCount > 1) {
            return $this->error('Un souvenir ne peut avoir qu\'une seule photo actuelle (type "after").', Response::HTTP_BAD_REQUEST);
        }
        if ($afterCount === 1 && $memory->getApprovedAfterPicture()) {
            return $this->error('Ce souvenir possède déjà une photo actuelle (type "after").', Response::HTTP_CONFLICT);
        }

        return null;
    }

    /**
     * Valide le nouveau type d'une photo existante (modification).
     * Une photo non approuvée (proposition en cours de modération) garde son type.
     */
    public function validateTypeChange(Picture $picture, mixed $type): ?JsonResponse
    {
        if (!is_string($type) || !in_array($type, Picture::getAllowedTypes(), true)) {
            return $this->error(sprintf('Type de photo invalide : type doit valoir %s.', implode(', ', Picture::getAllowedTypes())), Response::HTTP_BAD_REQUEST);
        }
        if ($type === $picture->getType()) {
            return null;
        }
        if (!$picture->isApproved()) {
            return $this->error('Le type d\'une photo en attente ou rejetée ne peut pas être modifié.', Response::HTTP_CONFLICT);
        }

        $existingAfter = $picture->getMemory()?->getApprovedAfterPicture();
        if ($type === Picture::TYPE_AFTER && $existingAfter && $existingAfter !== $picture) {
            return $this->error('Ce souvenir possède déjà une photo actuelle (type "after").', Response::HTTP_CONFLICT);
        }

        return null;
    }

    private function error(string $message, int $status): JsonResponse
    {
        return new JsonResponse(['error' => $message], $status);
    }
}
