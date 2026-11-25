<?php

namespace App\Repository;

use App\Entity\Location;
use App\Entity\Memory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Location>
 *
 * @method Location|null find($id, $lockMode = null, $lockVersion = null)
 * @method Location|null findOneBy(array $criteria, array $orderBy = null)
 * @method Location[]    findAll()
 * @method Location[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class LocationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Location::class);
    }

    /**
     * @return string[] Noms de ville distincts (une Location sans souvenir est supprimée : chaque ville a donc des souvenirs)
     */
    public function findDistinctCities(): array
    {
        $rows = $this->createQueryBuilder('l')
            ->select('DISTINCT l.city')
            ->getQuery()
            ->getSingleColumnResult();

        return array_values(array_filter($rows));
    }

    /**
     * Récupère une localisation avec ses souvenirs associés
     */
    public function findOneWithMemories(int $id): ?Location
    {
        return $this->createQueryBuilder('l')
            ->leftJoin('l.memories', 'm')
            ->addSelect('m')
            ->where('l.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Une Location est orpheline si ni elle ni aucun de ses Place ne porte de souvenir.
     *
     * @param int[] $excludedMemoryIds souvenirs ignorés (en cours de suppression)
     */
    public function isOrphan(Location $location, array $excludedMemoryIds = []): bool
    {
        $qb = $this->getEntityManager()->createQueryBuilder()
            ->select('COUNT(m.id)')
            ->from(Memory::class, 'm')
            ->leftJoin('m.place', 'p')
            ->where('m.location = :location OR p.location = :location')
            ->setParameter('location', $location);

        if ($excludedMemoryIds) {
            $qb->andWhere('m.id NOT IN (:excluded)')->setParameter('excluded', $excludedMemoryIds);
        }

        return (int) $qb->getQuery()->getSingleScalarResult() === 0;
    }

    /**
     * @return Location[] Locations sans aucun souvenir (directement ou via leurs Place)
     */
    public function findOrphans(): array
    {
        return $this->createQueryBuilder('l')
            ->where('NOT EXISTS (SELECT m1.id FROM ' . Memory::class . ' m1 WHERE m1.location = l)')
            ->andWhere('NOT EXISTS (SELECT m2.id FROM ' . Memory::class . ' m2 JOIN m2.place p2 WHERE p2.location = l)')
            ->orderBy('l.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findByOrderAlphabeticalStreet($direction): array
    {
        $sql = "SELECT 
                l.id as locationId, l.street, l.zipcode, l.city, 
                m.*, 
                p.name as placeName, p.type,
                u.pseudonym as userPseudonym
                FROM location l
                LEFT JOIN memory m ON l.id = m.location_id
                LEFT JOIN place p ON l.id = p.location_id
                LEFT JOIN user u ON m.user_id = u.id
                ORDER BY SUBSTRING(l.street, LOCATE(' ', l.street) + 1) $direction";

        $conn = $this->getEntityManager()->getConnection();
        $resultSet = $conn->executeQuery($sql);

        return $resultSet->fetchAllAssociative();
    }

    //    /**
    //     * @return Location[] Renvoie un tableau d'objets Location
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('l')
    //            ->andWhere('l.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('l.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?Location
    //    {
    //        return $this->createQueryBuilder('l')
    //            ->andWhere('l.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}
