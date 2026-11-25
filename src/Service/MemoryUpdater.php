<?php

namespace App\Service;

use App\Dto\Memory\UpdateMemoryRequest;
use App\Entity\Memory;
use App\Entity\Picture;
use App\Entity\User;
use App\Exception\ApiResponseException;
use App\Repository\PlaceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Modifie un souvenir : texte et datation, lieu, retrait et ajout de photos, choix de la photo « avant ».
 * Les droits (auteur seul) sont contrôlés par l'appelant. Tout refus lève une `ApiResponseException` :
 * rien n'est enregistré tant que tout n'est pas validé.
 */
class MemoryUpdater
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PlaceRepository $placeRepository,
        private readonly MemoryHydrator $hydrator,
        private readonly MemoryPictureUploader $pictureUploader,
        private readonly ApiValidationErrorResponder $validationErrorResponder,
        private readonly PictureRemovalValidator $pictureRemovalValidator,
        private readonly BeforePictureChooser $beforePictureChooser,
    ) {
    }

    /** @throws ApiResponseException */
    public function update(Memory $memory, UpdateMemoryRequest $dto, Request $request, User $user): void
    {
        // Le lieu n'est modifié que s'il existe : un id inconnu est ignoré
        $currentPlace = null;
        if ($dto->placeUpdate !== null) {
            $currentPlace = $this->placeRepository->find($dto->placeUpdate->placeId);
            $currentPlace?->setName($dto->placeUpdate->name)
                ->setType($dto->placeUpdate->type)
                ->setUpdatedAt(new \DateTimeImmutable());
        }

        $this->hydrator->apply($memory, $dto->memory);
        $memory->setUpdatedAt(new \DateTimeImmutable());

        // Photos à retirer : seulement les siennes (403 sinon), vérifié avant de toucher au souvenir
        $deleteIds = $this->deletedPictureIds($request);
        $picturesToRemove = $memory->getPictures()->filter(fn (Picture $picture) => in_array($picture->getId(), $deleteIds))->toArray();
        if ($error = $this->pictureRemovalValidator->forbiddenFor($user, $picturesToRemove)) {
            throw new ApiResponseException($error);
        }
        foreach ($picturesToRemove as $picture) {
            // VichUploader supprime le fichier avec la photo (orphanRemoval)
            $memory->removePicture($picture);
        }

        $this->pictureUploader->attach($memory, $request, $user, true);

        // Photo « avant » du comparateur choisie par l'auteur, parmi les photos qui restent après les retraits
        $beforeChoice = $request->request->get('before_picture_id');
        if ($beforeChoice !== null && $beforeChoice !== '') {
            $chosen = $this->beforePictureChooser->find($memory, $beforeChoice);
            if ($chosen === null) {
                throw new ApiResponseException($this->validationErrorResponder->createResponse(['before_picture_id' => [$this->beforePictureChooser->errorMessage()]]));
            }
            $this->beforePictureChooser->apply($memory, $chosen);
        }

        // Une photo « avant » ou de la galerie retirée doit rester remplacée (dans cette modification ou déjà présente)
        if ($error = $this->pictureRemovalValidator->lastPictureError($memory, $picturesToRemove)) {
            throw new ApiResponseException($error);
        }

        // Validation après retraits et ajouts : au moins une photo doit rester
        $toValidate = ['memory' => $memory];
        if ($currentPlace) {
            $toValidate['place'] = $currentPlace;
        }
        if ($errorResponse = $this->validationErrorResponder->validate($toValidate)) {
            throw new ApiResponseException($errorResponse);
        }

        $this->entityManager->persist($memory);
        $this->entityManager->flush();
    }

    /** @return list<mixed> ids du champ `delete_pictures` (tableau JSON) */
    private function deletedPictureIds(Request $request): array
    {
        $raw = $request->request->all()['delete_pictures'] ?? '[]';
        $ids = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($ids) ? array_values($ids) : [];
    }
}
