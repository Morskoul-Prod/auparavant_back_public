<?php

namespace App\Service;

use App\Entity\Memory;
use App\Entity\Picture;
use App\Enum\PictureStatus;
use App\Exception\QuizException;
use App\Repository\MemoryRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Éligibilité et tirage des souvenirs du quiz.
 *
 * Éligible : année effective (`Memory::getEffectiveYear()`), Location avec coordonnées valides, photo principale
 * approuvée. Un souvenir supprimé n'existe plus en base ; `Memory::publishedAt` n'est pas alimenté et n'est donc
 * pas un critère.
 */
class QuizMemorySelector
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MemoryRepository $memoryRepository,
    ) {
    }

    /**
     * Photo montrée au joueur : approuvée, jamais la photo actuelle (« after », qui montrerait la réponse) ;
     * le type « before » est préféré, sinon la première par position.
     */
    public function mainPicture(Memory $memory): ?Picture
    {
        $candidates = array_values(array_filter(
            $memory->getApprovedPictures(),
            fn (Picture $p) => !$p->isAfter() && $p->getImageName(),
        ));
        usort($candidates, fn (Picture $a, Picture $b) => [$a->getType() === Picture::TYPE_BEFORE ? 0 : 1, $a->getPosition()]
            <=> [$b->getType() === Picture::TYPE_BEFORE ? 0 : 1, $b->getPosition()]);

        return $candidates[0] ?? null;
    }

    public function isEligible(Memory $memory): bool
    {
        $location = $memory->getLocation();

        return $memory->getEffectiveYear() !== null
            && $location !== null
            && $this->validCoordinates($location->getLatitude(), $location->getLongitude())
            && $this->mainPicture($memory) !== null;
    }

    /**
     * Tire `$count` souvenirs éligibles au hasard, en privilégiant des Location différentes
     * (une même Location n'est reprise qu'une fois toutes les autres épuisées).
     *
     * @param list<int> $excludedIds souvenirs à ne pas retirer
     * @return list<Memory>
     * @throws QuizException s'il n'y en a pas assez
     */
    public function pick(int $count, array $excludedIds = []): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('m.id AS id', 'IDENTITY(m.location) AS locationId', 'l.latitude AS lat', 'l.longitude AS lng')
            ->from(Memory::class, 'm')
            ->join('m.location', 'l')
            ->where('l.latitude IS NOT NULL')
            ->andWhere('l.longitude IS NOT NULL')
            ->andWhere('m.year IS NOT NULL OR m.picture_date IS NOT NULL')
            ->andWhere('EXISTS (SELECT p.id FROM ' . Picture::class . ' p WHERE p.memory = m AND p.status = :approved AND (p.type IS NULL OR p.type <> :after))')
            ->setParameter('approved', PictureStatus::APPROVED)
            ->setParameter('after', Picture::TYPE_AFTER);
        if ($excludedIds) {
            $qb->andWhere('m.id NOT IN (:excluded)')->setParameter('excluded', $excludedIds);
        }

        $byLocation = [];
        foreach ($qb->getQuery()->getArrayResult() as $row) {
            if ($this->validCoordinates($row['lat'], $row['lng'])) {
                $byLocation[$row['locationId']][] = (int) $row['id'];
            }
        }

        $picked = [];
        while (count($picked) < $count && $byLocation) {
            $locations = array_keys($byLocation);
            shuffle($locations);
            foreach ($locations as $locationId) {
                if (count($picked) >= $count) {
                    break;
                }
                $ids = $byLocation[$locationId];
                $index = array_rand($ids);
                $picked[] = $ids[$index];
                unset($ids[$index]);
                if ($ids) {
                    $byLocation[$locationId] = array_values($ids);
                } else {
                    unset($byLocation[$locationId]);
                }
            }
        }

        if (count($picked) < $count) {
            throw QuizException::notEnoughMemories();
        }

        $memories = [];
        foreach ($this->memoryRepository->findBy(['id' => $picked]) as $memory) {
            $memories[$memory->getId()] = $memory;
        }

        // Dans l'ordre du tirage ; un souvenir qui ne passerait pas le contrôle complet est écarté
        $result = array_values(array_filter(array_map(fn (int $id) => $memories[$id] ?? null, $picked), fn (?Memory $m) => $m !== null && $this->isEligible($m)));
        if (count($result) < $count) {
            throw QuizException::notEnoughMemories();
        }

        return $result;
    }

    private function validCoordinates(?string $lat, ?string $lng): bool
    {
        return is_numeric($lat) && is_numeric($lng)
            && abs((float) $lat) <= 90 && abs((float) $lng) <= 180;
    }
}
