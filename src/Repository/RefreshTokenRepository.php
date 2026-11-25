<?php

namespace App\Repository;

use App\Entity\RefreshToken;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RefreshToken>
 */
class RefreshTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RefreshToken::class);
    }

    public function findOneByHash(string $hash): ?RefreshToken
    {
        return $this->findOneBy(['tokenHash' => $hash]);
    }

    /**
     * Marque le jeton comme utilisé, uniquement s'il ne l'était pas : l'UPDATE conditionnel est atomique,
     * donc de deux requêtes simultanées une seule obtient 1.
     */
    public function markUsed(RefreshToken $token, \DateTimeImmutable $now): int
    {
        return $this->createQueryBuilder('t')
            ->update()
            ->set('t.usedAt', ':now')
            ->where('t.id = :id')
            ->andWhere('t.usedAt IS NULL')
            ->andWhere('t.revokedAt IS NULL')
            ->setParameter('now', $now)
            ->setParameter('id', $token->getId())
            ->getQuery()
            ->execute();
    }

    public function revokeFamily(string $familyId, \DateTimeImmutable $now): void
    {
        $this->createQueryBuilder('t')
            ->update()
            ->set('t.revokedAt', ':now')
            ->where('t.familyId = :family')
            ->andWhere('t.revokedAt IS NULL')
            ->setParameter('now', $now)
            ->setParameter('family', $familyId)
            ->getQuery()
            ->execute();
    }

    public function revokeAllForUser(User $user, \DateTimeImmutable $now): void
    {
        $this->createQueryBuilder('t')
            ->update()
            ->set('t.revokedAt', ':now')
            ->where('t.user = :user')
            ->andWhere('t.revokedAt IS NULL')
            ->setParameter('now', $now)
            ->setParameter('user', $user)
            ->getQuery()
            ->execute();
    }

    public function countExpiredBefore(\DateTimeImmutable $limit): int
    {
        return (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->where('t.expiresAt < :limit')
            ->setParameter('limit', $limit)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function deleteExpiredBefore(\DateTimeImmutable $limit): int
    {
        return $this->createQueryBuilder('t')
            ->delete()
            ->where('t.expiresAt < :limit')
            ->setParameter('limit', $limit)
            ->getQuery()
            ->execute();
    }
}
