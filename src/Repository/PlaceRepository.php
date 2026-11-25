<?php

namespace App\Repository;

use App\Entity\Memory;
use App\Entity\Place;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Place>
 *
 * @method Place|null find($id, $lockMode = null, $lockVersion = null)
 * @method Place|null findOneBy(array $criteria, array $orderBy = null)
 * @method Place[]    findAll()
 * @method Place[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class PlaceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Place::class);
    }

    /**
     * @param int[] $excludedMemoryIds souvenirs ignorés (en cours de suppression)
     */
    public function isOrphan(Place $place, array $excludedMemoryIds = []): bool
    {
        $qb = $this->getEntityManager()->createQueryBuilder()
            ->select('COUNT(m.id)')
            ->from(Memory::class, 'm')
            ->where('m.place = :place')
            ->setParameter('place', $place);

        if ($excludedMemoryIds) {
            $qb->andWhere('m.id NOT IN (:excluded)')->setParameter('excluded', $excludedMemoryIds);
        }

        return (int) $qb->getQuery()->getSingleScalarResult() === 0;
    }

    /**
     * Récupère une place avec ses souvenirs associés
     */
    public function findOneWithMemories(int $id): ?Place
    {
        return $this->createQueryBuilder('p')
            ->leftJoin('p.memories', 'm')
            ->addSelect('m')
            ->where('p.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findByOrderAlphabeticalPlace($direction): array
    {
        $sql = "SELECT
        p.id as placeId, p.name as placeName, p.type as type,
        m.*,
        l.street as street, l.zipcode as zipcode, l.city as city,
        u.pseudonym as userPseudonym
    FROM place p
    LEFT JOIN memory m ON p.id = m.place_id
    LEFT JOIN location l ON m.location_id = l.id
    LEFT JOIN user u ON m.user_id = u.id

    ORDER BY
        p.name $direction";

        $conn = $this->getEntityManager()->getConnection();
        $resultSet = $conn->executeQuery($sql);

        return $resultSet->fetchAllAssociative();
    }
}
