<?php

namespace App\Service;

use App\Dto\Memory\CreateMemoryRequest;
use App\Dto\Memory\CreateMemoryWithLocationRequest;
use App\Dto\Memory\NewPlace;
use App\Entity\Location;
use App\Entity\Memory;
use App\Entity\Place;
use App\Entity\User;
use App\Exception\ApiResponseException;
use App\Repository\LocationRepository;
use App\Repository\PlaceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Crée un souvenir et, selon la route, sa localisation et son lieu. Les erreurs de fond (localisation ou lieu
 * introuvable, entité invalide, photos refusées) lèvent une `ApiResponseException`.
 */
class MemoryCreator
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LocationRepository $locationRepository,
        private readonly PlaceRepository $placeRepository,
        private readonly MemoryHydrator $hydrator,
        private readonly MemoryPictureUploader $pictureUploader,
        private readonly ApiValidationErrorResponder $validationErrorResponder,
        private readonly LocationMemoryNotifier $locationMemoryNotifier,
    ) {
    }

    /**
     * Souvenir sur une localisation existante, avec un lieu existant ou à créer.
     *
     * @throws ApiResponseException
     */
    public function create(CreateMemoryRequest $dto, Request $request, User $user): Memory
    {
        $location = $this->locationRepository->find($dto->locationId)
            ?? throw ApiResponseException::error('Location not found', Response::HTTP_NOT_FOUND);

        // address_id optionnel : renseigné uniquement si la localisation n'en a pas encore
        if ($dto->addressId !== null && $location->getAddressId() === null) {
            $location->setAddressId($dto->addressId);
        }

        if ($dto->newPlace !== null) {
            $place = $this->newPlace($dto->newPlace, $location);
            $this->entityManager->persist($place);
        } else {
            $place = $this->placeRepository->find($dto->placeId)
                ?? throw ApiResponseException::error('Place not found', Response::HTTP_NOT_FOUND);
        }

        $memory = (new Memory())->setUser($user)->setLocation($location)->setPlace($place);
        $this->hydrator->apply($memory, $dto->memory);
        // Avant la validation : un souvenir doit avoir au moins une photo
        $this->pictureUploader->attach($memory, $request, $user);

        // Le lieu n'est validé que s'il vient d'être créé
        $toValidate = ['memory' => $memory];
        if ($place->getId() === null) {
            $toValidate['place'] = $place;
        }
        $this->assertValid($toValidate);

        $this->entityManager->persist($memory);
        // Les autres contributeurs de la localisation sont notifiés dans le même flush
        $this->locationMemoryNotifier->notify($memory);
        $this->entityManager->flush();

        return $memory;
    }

    /**
     * Souvenir avec une nouvelle localisation et un nouveau lieu.
     *
     * @throws ApiResponseException
     */
    public function createWithLocation(CreateMemoryWithLocationRequest $dto, Request $request, User $user): Memory
    {
        $data = $dto->location;
        $location = (new Location())
            ->setArea($data->area)
            ->setDepartment($data->department)
            ->setDistrict($data->district)
            ->setStreet($data->street)
            ->setCity($data->city)
            ->setZipcode($data->zipcode)
            ->setLatitude($data->latitude)
            ->setLongitude($data->longitude)
            ->setAddressId($data->addressId);
        $place = $this->newPlace($dto->place, $location);

        $memory = (new Memory())->setUser($user)->setPlace($place)->setLocation($location);
        $this->hydrator->apply($memory, $dto->memory);
        $this->pictureUploader->attach($memory, $request, $user);

        $this->assertValid(['memory' => $memory, 'place' => $place, 'location' => $location]);

        $this->entityManager->persist($location);
        $this->entityManager->persist($place);
        $this->entityManager->persist($memory);
        $this->entityManager->flush();

        return $memory;
    }

    private function newPlace(NewPlace $data, Location $location): Place
    {
        return (new Place())->setName($data->name)->setType($data->type)->setLocation($location);
    }

    /**
     * @param array<string, object> $objects
     *
     * @throws ApiResponseException
     */
    private function assertValid(array $objects): void
    {
        if ($errorResponse = $this->validationErrorResponder->validate($objects)) {
            throw new ApiResponseException($errorResponse);
        }
    }
}
