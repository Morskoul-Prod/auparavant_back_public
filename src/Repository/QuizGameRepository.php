<?php

namespace App\Repository;

use App\Entity\QuizGame;
use App\Entity\User;
use App\Enum\QuizGameStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<QuizGame>
 */
class QuizGameRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, QuizGame::class);
    }

    /** Partie d'un utilisateur pour un jour, avec ses manches (une seule requête) */
    public function findOneWithRounds(User $user, \DateTimeImmutable $day): ?QuizGame
    {
        return $this->createQueryBuilder('g')
            ->addSelect('r')
            ->leftJoin('g.rounds', 'r')
            ->where('g.user = :user')
            ->andWhere('g.date = :day')
            ->setParameter('user', $user)
            ->setParameter('day', $day, 'date_immutable')
            ->orderBy('r.position', 'ASC')
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Jours des parties terminées d'un utilisateur
     *
     * @return list<\DateTimeImmutable>
     */
    public function findFinishedDays(User $user): array
    {
        return array_column($this->createQueryBuilder('g')
            ->select('g.date AS day')
            ->where('g.user = :user')
            ->andWhere('g.status = :finished')
            ->setParameter('user', $user)
            ->setParameter('finished', QuizGameStatus::FINISHED)
            ->orderBy('g.date', 'ASC')
            ->getQuery()
            ->getArrayResult(), 'day');
    }

    /**
     * @return array{gamesPlayed: int, bestScore: int, averageScore: float}
     */
    public function summarize(User $user): array
    {
        $row = $this->createQueryBuilder('g')
            ->select('COUNT(g.id) AS gamesPlayed', 'MAX(g.totalScore) AS bestScore', 'AVG(g.totalScore) AS averageScore')
            ->where('g.user = :user')
            ->andWhere('g.status = :finished')
            ->setParameter('user', $user)
            ->setParameter('finished', QuizGameStatus::FINISHED)
            ->getQuery()
            ->getSingleResult();

        return [
            'gamesPlayed' => (int) $row['gamesPlayed'],
            'bestScore' => (int) $row['bestScore'],
            'averageScore' => round((float) $row['averageScore'], 1),
        ];
    }

    /** Parties abandonnées : jamais terminées, d'un jour strictement antérieur à `$limit` */
    public function countAbandonedBefore(\DateTimeImmutable $limit): int
    {
        return (int) $this->abandonedBefore($limit)->select('COUNT(g.id)')->getQuery()->getSingleScalarResult();
    }

    /** Supprime les parties abandonnées (leurs manches partent avec, par la clé étrangère en CASCADE) */
    public function deleteAbandonedBefore(\DateTimeImmutable $limit): int
    {
        return (int) $this->createQueryBuilder('g')
            ->delete()
            ->where('g.status = :status')
            ->andWhere('g.date < :limit')
            ->setParameter('status', QuizGameStatus::IN_PROGRESS)
            ->setParameter('limit', $limit, Types::DATE_IMMUTABLE)
            ->getQuery()
            ->execute();
    }

    private function abandonedBefore(\DateTimeImmutable $limit): QueryBuilder
    {
        return $this->createQueryBuilder('g')
            ->where('g.status = :status')
            ->andWhere('g.date < :limit')
            ->setParameter('status', QuizGameStatus::IN_PROGRESS)
            ->setParameter('limit', $limit, Types::DATE_IMMUTABLE);
    }
}
