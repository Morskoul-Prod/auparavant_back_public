<?php

// src/Service/MemoryProcessor.php

namespace App\Service;

use App\Entity\Memory;
use App\Enum\PictureStatus;
use App\Repository\MemoryRepository;
use App\Repository\PictureRepository;

class MemoryProcessor
{
    private $memoryRepository;
    private $pictureRepository;

    public function __construct(
        MemoryRepository $memoryRepository,
        PictureRepository $pictureRepository,
        private readonly MemoryLikeCounts $memoryLikeCounts,
    ) {
        $this->memoryRepository = $memoryRepository;
        $this->pictureRepository = $pictureRepository;
    }

    /**
     * Récupère la première image approuvée d'un memory par son ID
     */
    private function getFirstPictureByMemoryId(int $memoryId): ?string
    {
        $picture = $this->pictureRepository->findOneBy(
            ['memory' => $memoryId, 'status' => PictureStatus::APPROVED],
            ['position' => 'ASC']
        );

        return $picture ? $picture->getImageName() : null;
    }

    /**
     * Parcourt tous les souvenirs et récupère, pour chaque localisation, la photo la plus récente et la plus ancienne.
     * Compare les dates des souvenirs pour savoir si le souvenir est le plus récent.
     * Si le souvenir est le plus récent, on lui associe la photo du plus ancien souvenir de la localité ;
     * Sinon, on lui associe la photo la plus récente de la localité ;
     */
    public function processMemories(array $memories): array
    {
        $processedMemories = [];
        $likesCounts = $this->memoryLikeCounts->countsFor($memories);
        $commentsCounts = $this->memoryLikeCounts->commentCountsFor($memories);
        foreach ($memories as $memory) {
            $locationId = $memory->getLocation()->getId();

            // Cherche le souvenir le plus récent de cette localisation
            $mostRecentMemory = $this->memoryRepository->findMostRecentMemoryByLocation($locationId);
            $mostRecentPicture = $mostRecentMemory ? $this->getFirstPictureByMemoryId($mostRecentMemory['id']) : null;

            // Cherche le plus ancien souvenir de cette localisation
            $oldestMemory = $this->memoryRepository->findOldestMemoryByLocation($locationId);
            $oldestPicture = $oldestMemory ? $this->getFirstPictureByMemoryId($oldestMemory['id']) : null;

            // Compare les souvenirs par année effective, puis par date exacte si les deux en ont une
            $order = $mostRecentMemory ? $this->compareChronologically($memory, $mostRecentMemory) : null;

            // Compare les dates pour déterminer le plus récent ou le plus ancien
            if ($order !== null && $order < 0) {
                $comparePicture = $mostRecentPicture;
            } elseif ($order === 0) {
                $comparePicture = $oldestPicture;
            } else {
                $comparePicture = null;
            }

            // Iterate over each approved picture and store in array $pictures[] (les photos en attente/rejetées ne sont pas publiques)
            $approvedPictures = $memory->getApprovedPictures();
            $pictures = [];
            foreach ($approvedPictures as $picture) {
                $pictures[] = [
                    'id' => $picture->getId(),
                    'imageName' => $picture->getImageName(),
                    'imageUrl' => $picture->getImageUrl(),
                    'type' => $picture->getType(),
                    'position' => $picture->getPosition(),
                    'status' => $picture->getStatus()->value,
                    'uploadedBy' => $picture->getUploadedById(),
                ];
            }

            // Récupérer la première image du memory courant
            $firstPicture = $approvedPictures[0] ?? null;
            $mainPicture = $firstPicture ? $firstPicture->getImageName() : null;

            $processedMemories[] = [
                'id' => $memory->getId(),
                'title' => $memory->getTitle(),
                'content' => $memory->getContent(),
                'picture_date' => $memory->getPictureDate(), // null pour un souvenir daté par l'année seule
                'slug' => $memory->getSlug(),
                'year' => $memory->getEffectiveYear(),
                'likesCount' => $likesCounts[$memory->getId()],
                'commentsCount' => $commentsCounts[$memory->getId()],
                'main_picture' => $mainPicture, // Première photo de la collection
                'compare_picture' => $comparePicture,
                'location' => [
                    'id' => $memory->getLocation()->getId(),
                    'area' => $memory->getLocation()->getArea(),
                    'department' => $memory->getLocation()->getDepartment(),
                    'district' => $memory->getLocation()->getDistrict(),
                    'street' => $memory->getLocation()->getStreet(),
                    'city' => $memory->getLocation()->getCity(),
                    'citySlug' => $memory->getLocation()->getCitySlug(),
                    'zipcode' => $memory->getLocation()->getZipcode(),
                    'latitude' => $memory->getLocation()->getLatitude(),
                    'longitude' => $memory->getLocation()->getLongitude(),
                    'address_id' => $memory->getLocation()->getAddressId(),
                ],
                'pictures' => $pictures,
                'user' => [
                    'id' => $memory->getUser()->getId(),
                    'displayName' => $memory->getUser()->getDisplayName(),
                ],
                'place' => [
                    'id' => $memory->getPlace()->getId(),
                    'name' => $memory->getPlace()->getName(),
                    'type' => $memory->getPlace()->getType()->value,
                ],
            ];
        }

        return $processedMemories;
    }

    /**
     * Compare un souvenir à une ligne SQL brute de souvenir : -1 s'il est antérieur, 1 s'il est postérieur,
     * 0 s'ils sont indiscernables (même année et, si les deux ont une date exacte, même date).
     * Retourne null si l'un des deux n'a ni date ni année.
     */
    private function compareChronologically(Memory $memory, array $row): ?int
    {
        $rowDate = $row['picture_date'] ? new \DateTime($row['picture_date']) : null;
        $rowYear = $rowDate ? (int) $rowDate->format('Y') : ($row['year'] !== null ? (int) $row['year'] : null);
        $year = $memory->getEffectiveYear();

        if ($year === null || $rowYear === null) {
            return null;
        }
        if ($year !== $rowYear) {
            return $year <=> $rowYear;
        }

        $date = $memory->getPictureDate();

        return ($date && $rowDate) ? ($date <=> $rowDate) : 0;
    }
}
