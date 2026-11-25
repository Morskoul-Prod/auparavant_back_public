<?php

namespace App\Repository;

use App\Entity\Picture;
use App\Entity\User;
use App\Enum\PictureStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Picture>
 *
 * @method Picture|null find($id, $lockMode = null, $lockVersion = null)
 * @method Picture|null findOneBy(array $criteria, array $orderBy = null)
 * @method Picture[]    findAll()
 * @method Picture[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class PictureRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Picture::class);
    }

    /**
     * Récupère toutes les photos triées par memory_id.
     *
     * @return array
     */
    public function findAllPicturesOrderedByMemoryId(): array
    {
        return $this->createQueryBuilder('p')
            ->leftJoin('p.memory', 'm') // Assuming the property name for the Memory association is 'memory'
            ->orderBy('m.id', 'ASC')    // Order by memory_id
            ->getQuery()
            ->getResult();
    }

    public function findByOrderAlphabeticalMemory($direction): array
    {
        $sql = "SELECT  m.title, 
                p.*
                FROM picture as p
                LEFT JOIN memory m ON p.memory_id = m.id
                ORDER BY m.title $direction";

        $conn = $this->getEntityManager()->getConnection();
        $resultSet = $conn->executeQuery($sql);

        return $resultSet->fetchAllAssociative();
    }

    /**
     * Photos en attente de modération, les plus anciennes d'abord
     *
     * @return Picture[]
     */
    public function findPending(): array
    {
        return $this->createQueryBuilder('p')
            ->leftJoin('p.memory', 'm')
            ->leftJoin('p.uploadedBy', 'u')
            ->addSelect('m', 'u')
            ->where('p.status = :status')
            ->setParameter('status', PictureStatus::PENDING->value)
            ->orderBy('p.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Nombre de photos en attente de modération
     */
    public function countPending(): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('p.status = :status')
            ->setParameter('status', PictureStatus::PENDING->value)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Photos refusées de l'utilisateur dont il n'a pas encore vu la notification, les plus récentes d'abord
     *
     * @return Picture[]
     */
    public function findUnseenRejectedByUser(User $user): array
    {
        return $this->createQueryBuilder('p')
            ->leftJoin('p.memory', 'm')
            ->addSelect('m')
            ->where('p.uploadedBy = :user')
            ->andWhere('p.status = :status')
            ->andWhere('p.rejectionSeenAt IS NULL')
            ->setParameter('user', $user)
            ->setParameter('status', PictureStatus::REJECTED->value)
            ->orderBy('p.moderatedAt', 'DESC')
            ->addOrderBy('p.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Photos refusées avant la limite, vues ou non (ou sans date de modération : refus antérieurs aux notifications)
     *
     * @return Picture[]
     */
    public function findRejectedBefore(\DateTimeImmutable $limit): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.status = :status')
            ->andWhere('p.moderatedAt IS NULL OR p.moderatedAt < :limit')
            ->setParameter('status', PictureStatus::REJECTED->value)
            ->setParameter('limit', $limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Photos actuelles (type after) proposées par un utilisateur, de la plus récente à la plus ancienne (paginées).
     * Tous statuts, ou un seul si $status est renseigné.
     * Le souvenir est chargé avec la photo (pas de N+1) ; ses photos sont à charger via MemoryRepository::loadPictures().
     *
     * @return Picture[]
     */
    public function findAfterByUploader(User $user, int $page, int $limit, ?PictureStatus $status = null): array
    {
        $qb = $this->createQueryBuilder('p')
            ->join('p.memory', 'm')
            ->addSelect('m')
            ->where('p.uploadedBy = :user')
            ->andWhere('p.type = :type')
            ->setParameter('user', $user)
            ->setParameter('type', Picture::TYPE_AFTER)
            ->orderBy('p.createdAt', 'DESC')
            ->addOrderBy('p.id', 'DESC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit);

        if ($status !== null) {
            $qb->andWhere('p.status = :status')->setParameter('status', $status);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Nombre de photos actuelles (type after) proposées par un utilisateur, par statut
     *
     * @return array<string, int> statut (pending|approved|rejected) => nombre ; statut absent = 0
     */
    public function countAfterByUploaderGroupedByStatus(User $user): array
    {
        $rows = $this->createQueryBuilder('p')
            ->select('p.status AS status', 'COUNT(p.id) AS total')
            ->where('p.uploadedBy = :user')
            ->andWhere('p.type = :type')
            ->setParameter('user', $user)
            ->setParameter('type', Picture::TYPE_AFTER)
            ->groupBy('p.status')
            ->getQuery()
            ->getScalarResult();

        $counts = [];
        foreach ($rows as $row) {
            $status = $row['status'] instanceof PictureStatus ? $row['status']->value : $row['status'];
            $counts[$status] = (int) $row['total'];
        }

        return $counts;
    }

    //    /**
    //     * @return Picture[] Renvoie un tableau d'objets Picture
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('p')
    //            ->andWhere('p.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('p.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?Picture
    //    {
    //        return $this->createQueryBuilder('p')
    //            ->andWhere('p.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}
