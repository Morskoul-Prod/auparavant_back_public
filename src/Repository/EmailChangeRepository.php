<?php

namespace App\Repository;

use App\Entity\EmailChange;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EmailChange>
 */
class EmailChangeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EmailChange::class);
    }

    /** Demande non confirmée la plus récente du compte (son jeton a pu expirer). */
    public function findPendingForUser(User $user): ?EmailChange
    {
        return $this->findOneBy(['user' => $user, 'confirmedAt' => null], ['id' => 'DESC']);
    }

    public function findOneByConfirmTokenHash(string $hash): ?EmailChange
    {
        return $this->findOneBy(['confirmTokenHash' => $hash]);
    }

    public function findOneByRevertTokenHash(string $hash): ?EmailChange
    {
        return $this->findOneBy(['revertTokenHash' => $hash]);
    }

    /** Supprime les demandes non confirmées du compte (les confirmées gardent leur lien de rétablissement). */
    public function removePendingForUser(User $user): void
    {
        $this->createQueryBuilder('c')
            ->delete()
            ->where('c.user = :user')
            ->andWhere('c.confirmedAt IS NULL')
            ->setParameter('user', $user)
            ->getQuery()
            ->execute();
    }

    /**
     * Invalide les liens de rétablissement du changement `$changeId` et des changements postérieurs. Les liens des
     * changements ANTÉRIEURS restent valables : si un pirate enchaîne deux changements et clique son propre lien,
     * celui envoyé au propriétaire légitime doit continuer à fonctionner.
     */
    public function clearRevertTokensFrom(User $user, int $changeId): void
    {
        $this->createQueryBuilder('c')
            ->update()
            ->set('c.revertTokenHash', ':none')
            ->where('c.user = :user')
            ->andWhere('c.id >= :id')
            ->setParameter('none', null)
            ->setParameter('user', $user)
            ->setParameter('id', $changeId)
            ->getQuery()
            ->execute();
    }

    /** Changement dont le lien de rétablissement, encore valable, protège cette ancienne adresse. */
    public function findRevertReservation(string $email, \DateTimeImmutable $now): ?EmailChange
    {
        return $this->createQueryBuilder('c')
            ->where('LOWER(c.oldEmail) = :email')
            ->andWhere('c.revertTokenHash IS NOT NULL')
            ->andWhere('c.revertExpiresAt > :now')
            ->setParameter('email', mb_strtolower($email))
            ->setParameter('now', $now)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
