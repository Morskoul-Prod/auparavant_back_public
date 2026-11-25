<?php

namespace App\Service;

use App\Entity\Location;
use App\Entity\Memory;
use App\Entity\Place;
use App\Repository\LocationRepository;
use App\Repository\PlaceRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Règle métier : une Location sans souvenir est supprimée (sinon son marker reste sur la carte).
 * Un Place sans souvenir est lui aussi supprimé quand sa Location survit.
 *
 * Le service ne fait que `remove()` : l'appelant garde la main sur le flush (et donc la transaction).
 */
class OrphanLocationCleaner
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LocationRepository $locationRepository,
        private readonly PlaceRepository $placeRepository,
    ) {
    }

    /**
     * À appeler avec les souvenirs que l'appelant supprime (ou s'apprête à supprimer, ex. suppression
     * d'un utilisateur ou d'un lieu en cascade), avant le flush : ils sont exclus du décompte.
     * Le flush de l'appelant supprime souvenirs et orphelins en une seule fois.
     *
     * @param Memory[] $memories
     */
    public function cleanAfterRemoval(array $memories): void
    {
        $this->purge(
            array_map(static fn (Memory $m) => $m->getLocation(), $memories),
            array_map(static fn (Memory $m) => $m->getPlace(), $memories),
            array_map(static fn (Memory $m) => $m->getId(), $memories),
        );
    }

    /**
     * À appeler APRÈS le flush qui a déplacé le souvenir : supprimer l'ancienne Location avant lui ferait
     * cascader (orphanRemoval) la suppression sur le souvenir encore lié en base. Pas de flush ici.
     */
    public function cleanAfterMove(Memory $memory, ?Location $oldLocation, ?Place $oldPlace): void
    {
        // Les collections déjà chargées peuvent contenir le souvenir déplacé : on les recharge
        foreach ([$oldLocation, $oldPlace] as $entity) {
            if ($entity && $this->entityManager->contains($entity)) {
                $this->entityManager->refresh($entity);
            }
        }

        $this->purge(
            $oldLocation !== $memory->getLocation() ? [$oldLocation] : [],
            $oldPlace !== $memory->getPlace() ? [$oldPlace] : [],
            [],
        );
    }

    /**
     * Supprime la Location et ses Place (lus en base : la collection `places` peut être incomplète).
     * Pas de flush.
     */
    public function removeLocation(Location $location): void
    {
        foreach ($this->placeRepository->findBy(['location' => $location]) as $place) {
            $this->entityManager->remove($place);
        }
        $this->entityManager->remove($location);
    }

    /**
     * @param array<Location|null> $locations
     * @param array<Place|null>    $places
     * @param array<int|null>      $excludedMemoryIds souvenirs en cours de suppression
     */
    private function purge(array $locations, array $places, array $excludedMemoryIds): void
    {
        $excludedMemoryIds = array_values(array_filter($excludedMemoryIds));
        $removedLocations = [];

        foreach ($this->distinct($locations) as $location) {
            if ($this->locationRepository->isOrphan($location, $excludedMemoryIds)) {
                $this->removeLocation($location);
                $removedLocations[] = $location;
            }
        }

        foreach ($this->distinct($places) as $place) {
            if (in_array($place->getLocation(), $removedLocations, true)) {
                continue;
            }
            if ($this->placeRepository->isOrphan($place, $excludedMemoryIds)) {
                $this->entityManager->remove($place);
            }
        }
    }

    /**
     * @template T of object
     * @param array<T|null> $entities
     * @return T[]
     */
    private function distinct(array $entities): array
    {
        $distinct = [];
        foreach ($entities as $entity) {
            if ($entity !== null) {
                $distinct[spl_object_id($entity)] = $entity;
            }
        }

        return $distinct;
    }
}
