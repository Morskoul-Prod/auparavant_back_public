<?php

namespace App\Repository;

use App\Entity\Memory;
use App\Entity\Picture;
use App\Entity\User;
use App\Enum\PictureStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Memory>
 *
 * @method Memory|null find($id, $lockMode = null, $lockVersion = null)
 * @method Memory|null findOneBy(array $criteria, array $orderBy = null)
 * @method Memory[]    findAll()
 * @method Memory[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class MemoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Memory::class);
    }

    /**
     * Récupère les trois derniers souvenirs par date de création
     *
     * @return array|null
     */
    public function findTheLatestOnes(): ?array
    {
        return $this->createQueryBuilder('memory')
            ->orderBy('memory.createdAt', 'DESC')
            ->setMaxResults(3)
            ->getQuery()
            ->getResult();
    }

    /**
     * Souvenirs créés par un utilisateur, du plus récent au plus ancien (paginés).
     * Deux requêtes : les ids de la page (la pagination ne supporte pas le join fetch d'une collection),
     * puis les souvenirs avec lieu, place, auteur et photos chargés d'un coup (pas de N+1).
     *
     * @return Memory[]
     */
    public function findByUser(User $user, int $page, int $limit): array
    {
        $ids = array_column($this->createQueryBuilder('m')
            ->select('m.id')
            ->where('m.user = :user')
            ->setParameter('user', $user)
            ->orderBy('m.createdAt', 'DESC')
            ->addOrderBy('m.id', 'DESC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getScalarResult(), 'id');

        if (!$ids) {
            return [];
        }

        $memories = $this->createQueryBuilder('m')
            ->join('m.location', 'l')
            ->join('m.place', 'pl')
            ->join('m.user', 'u')
            ->leftJoin('m.pictures', 'p')
            ->addSelect('l', 'pl', 'u', 'p')
            ->where('m.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();

        // Conserve l'ordre de la page (le IN ne le garantit pas)
        usort($memories, fn (Memory $a, Memory $b) => array_search($a->getId(), $ids) <=> array_search($b->getId(), $ids));

        return $memories;
    }

    /**
     * Charge d'un coup les photos des souvenirs donnés (initialise leur collection, évite un N+1)
     *
     * @param Memory[] $memories
     */
    public function loadPictures(array $memories): void
    {
        if (!$memories) {
            return;
        }

        $this->createQueryBuilder('m')
            ->leftJoin('m.pictures', 'p')
            ->addSelect('p')
            ->where('m IN (:memories)')
            ->setParameter('memories', $memories)
            ->getQuery()
            ->getResult();
    }

    /**
     * Nombre de souvenirs créés par un utilisateur
     */
    public function countByUser(User $user): int
    {
        return (int) $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->where('m.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Souvenir tiré au hasard parmi ceux qui ont une photo "before" ET une photo "after" approuvées
     * (les photos en attente ou refusées ne sont jamais exposées).
     */
    public function findRandomWithBeforeAfter(): ?Memory
    {
        $rows = $this->createQueryBuilder('m')
            ->select('DISTINCT m.id')
            ->innerJoin('m.pictures', 'b', 'WITH', 'b.type = :before AND b.status = :approved')
            ->innerJoin('m.pictures', 'a', 'WITH', 'a.type = :after AND a.status = :approved')
            ->setParameter('before', Picture::TYPE_BEFORE)
            ->setParameter('after', Picture::TYPE_AFTER)
            ->setParameter('approved', PictureStatus::APPROVED->value)
            ->getQuery()
            ->getScalarResult();

        if (empty($rows)) {
            return null;
        }

        return $this->find($rows[random_int(0, count($rows) - 1)]['id']);
    }

    /**
     * Tous les souvenirs, le dernier créé en premier (ordre de la liste publique). L'id départage deux créations
     * dans la même seconde.
     *
     * @return Memory[]
     */
    public function findAllNewestFirst(): array
    {
        return $this->findBy([], ['createdAt' => 'DESC', 'id' => 'DESC']);
    }

    /**
     * Souvenirs situés dans l'une des villes données, les plus récemment créés d'abord (page de partage d'une ville)
     *
     * @param string[] $cities
     * @return Memory[]
     */
    public function findByCities(array $cities, int $limit = 50): array
    {
        if (!$cities) {
            return [];
        }

        return $this->createQueryBuilder('m')
            ->innerJoin('m.location', 'l')
            ->where('l.city IN (:cities)')
            ->setParameter('cities', $cities)
            ->orderBy('m.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Récupère le souvenir le plus ancien par localité
     */
    public function findOldestMemoryByLocation(int $locationId): ?array
    {
        $sql = 'SELECT m.*
                FROM memory m
                WHERE m.location_id = :locationId
                ORDER BY COALESCE(m.year, YEAR(m.picture_date)) ASC, m.picture_date ASC
                LIMIT 1';

        $conn = $this->getEntityManager()->getConnection();
        $resultSet = $conn->executeQuery($sql, ['locationId' => $locationId]);

        return $resultSet->fetchAssociative();
    }

    /**
     * Récupère le souvenir le plus récent par localité
     */
    public function findMostRecentMemoryByLocation(int $locationId): ?array
    {
        $sql = 'SELECT m.*
                FROM memory m
                WHERE m.location_id = :locationId
                ORDER BY COALESCE(m.year, YEAR(m.picture_date)) DESC, m.picture_date DESC
                LIMIT 1';

        $conn = $this->getEntityManager()->getConnection();
        $resultSet = $conn->executeQuery($sql, ['locationId' => $locationId]);

        return $resultSet->fetchAssociative();
    }

    /**
     * Récupère tous les souvenirs d'un lieu triés par date (pour la fresque chronologique)
     *
     * @param int $placeId ID du lieu
     * @return array
     */
    public function findByPlaceOrderedByDate(int $placeId): array
    {
        return $this->createQueryBuilder('m')
            ->join('m.place', 'p')
            ->where('p.id = :placeId')
            ->setParameter('placeId', $placeId)
            ->addSelect('COALESCE(m.year, 0) AS HIDDEN sort_year') // year est toujours alimenté (déduit de picture_date)
            ->orderBy('sort_year', 'ASC')
            ->addOrderBy('m.picture_date', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Récupère tous les souvenirs d'une localisation triés par date (pour la fresque chronologique)
     *
     * @param int $locationId ID de la localisation
     * @return array
     */
    public function findByLocationOrderedByDate(int $locationId): array
    {
        return $this->createQueryBuilder('m')
            ->join('m.location', 'l')
            ->where('l.id = :locationId')
            ->setParameter('locationId', $locationId)
            ->addSelect('COALESCE(m.year, 0) AS HIDDEN sort_year') // year est toujours alimenté (déduit de picture_date)
            ->orderBy('sort_year', 'ASC')
            ->addOrderBy('m.picture_date', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Souvenirs situés à `$radiusKm` kilomètres au plus d'un point, du plus proche au plus éloigné.
     * Un rectangle englobant limite d'abord les lignes lues en base (coordonnées en DECIMAL), puis la distance
     * exacte (Haversine) est calculée en PHP : portable (MySQL / PostgreSQL) et sans les arrondis de `acos` qui
     * renvoient NULL pour un point exactement confondu.
     *
     * @return array<int, array{memory: Memory, distance: float}> `distance` en mètres
     */
    public function findNearby(float $latitude, float $longitude, float $radiusKm, int $limit): array
    {
        $latDelta = $radiusKm / 111.32;
        $qb = $this->createQueryBuilder('m')
            ->join('m.location', 'l')
            ->join('m.place', 'pl')
            ->join('m.user', 'u')
            ->leftJoin('m.pictures', 'p')
            ->addSelect('l', 'pl', 'u', 'p')
            ->where('l.latitude BETWEEN :minLat AND :maxLat')
            ->setParameter('minLat', $latitude - $latDelta)
            ->setParameter('maxLat', $latitude + $latDelta);

        // Près des pôles ou de l'antiméridien le rectangle en longitude n'a plus de sens : seule la distance exacte filtre
        $cos = cos(deg2rad($latitude));
        if ($cos > 0.01) {
            $lngDelta = $radiusKm / (111.32 * $cos);
            if ($longitude - $lngDelta >= -180 && $longitude + $lngDelta <= 180) {
                $qb->andWhere('l.longitude BETWEEN :minLng AND :maxLng')
                    ->setParameter('minLng', $longitude - $lngDelta)
                    ->setParameter('maxLng', $longitude + $lngDelta);
            }
        }

        $found = [];
        foreach ($qb->getQuery()->getResult() as $memory) {
            $distance = self::distanceMeters(
                $latitude,
                $longitude,
                (float) $memory->getLocation()->getLatitude(),
                (float) $memory->getLocation()->getLongitude()
            );
            if ($distance <= $radiusKm * 1000) {
                $found[] = ['memory' => $memory, 'distance' => $distance];
            }
        }
        usort($found, fn (array $a, array $b) => $a['distance'] <=> $b['distance'] ?: $a['memory']->getId() <=> $b['memory']->getId());

        return array_slice($found, 0, $limit);
    }

    /** Distance à vol d'oiseau entre deux points (formule de Haversine), en mètres */
    public static function distanceMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * 6371000 * asin(min(1.0, sqrt($a)));
    }

    /**
     * Récupère les souvenirs d'un lieu en excluant un souvenir spécifique
     * Utile pour afficher des souvenirs similaires
     *
     * @param int $placeId ID du lieu
     * @param int|null $excludeId ID du souvenir à exclure
     * @param int $limit Nombre maximum de résultats
     * @return array
     */
    public function findByPlaceExcluding(int $placeId, ?int $excludeId = null, int $limit = 6): array
    {
        $qb = $this->createQueryBuilder('m')
            ->join('m.place', 'p')
            ->where('p.id = :placeId')
            ->setParameter('placeId', $placeId);

        if ($excludeId !== null) {
            $qb->andWhere('m.id != :excludeId')
               ->setParameter('excludeId', $excludeId);
        }

        return $qb->orderBy('m.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Récupère les souvenirs d'une localisation en excluant un souvenir spécifique
     * Utile pour afficher des souvenirs similaires
     *
     * @param int $locationId ID de la localisation
     * @param int|null $excludeId ID du souvenir à exclure
     * @param int $limit Nombre maximum de résultats
     * @return array
     */
    public function findByLocationExcluding(int $locationId, ?int $excludeId = null, int $limit = 6): array
    {
        $qb = $this->createQueryBuilder('m')
            ->join('m.location', 'l')
            ->where('l.id = :locationId')
            ->setParameter('locationId', $locationId);

        if ($excludeId !== null) {
            $qb->andWhere('m.id != :excludeId')
               ->setParameter('excludeId', $excludeId);
        }

        return $qb->orderBy('m.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    //    /**
    //     * @return Memory[] Renvoie un tableau d'objets Memory
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('m')
    //            ->andWhere('m.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('m.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?Memory
    //    {
    //        return $this->createQueryBuilder('m')
    //            ->andWhere('m.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}
