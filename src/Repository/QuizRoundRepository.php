<?php

namespace App\Repository;

use App\Entity\QuizRound;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<QuizRound>
 */
class QuizRoundRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, QuizRound::class);
    }

    /**
     * Enregistre la réponse d'une manche si elle n'en a pas encore : mise à jour conditionnelle, atomique,
     * donc un double clic ou deux requêtes simultanées ne comptent qu'une fois.
     *
     * @param array{distanceKm: float, yearDiff: int, locationScore: int, yearScore: int, score: int} $scored
     * @return bool false si la manche avait déjà reçu une réponse
     */
    public function recordAnswer(QuizRound $round, float $lat, float $lng, int $year, array $scored, \DateTimeImmutable $at): bool
    {
        $affected = $this->createQueryBuilder('r')
            ->update()
            ->set('r.answerLat', ':lat')
            ->set('r.answerLng', ':lng')
            ->set('r.answerYear', ':year')
            ->set('r.distanceKm', ':distance')
            ->set('r.yearDiff', ':yearDiff')
            ->set('r.locationScore', ':locationScore')
            ->set('r.yearScore', ':yearScore')
            ->set('r.score', ':score')
            ->set('r.answeredAt', ':at')
            ->where('r.id = :id')
            ->andWhere('r.answeredAt IS NULL')
            ->setParameter('lat', $lat)
            ->setParameter('lng', $lng)
            ->setParameter('year', $year)
            ->setParameter('distance', $scored['distanceKm'])
            ->setParameter('yearDiff', $scored['yearDiff'])
            ->setParameter('locationScore', $scored['locationScore'])
            ->setParameter('yearScore', $scored['yearScore'])
            ->setParameter('score', $scored['score'])
            ->setParameter('at', $at)
            ->setParameter('id', $round->getId())
            ->getQuery()
            ->execute();

        return $affected === 1;
    }
}
