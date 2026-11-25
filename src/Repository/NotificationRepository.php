<?php

namespace App\Repository;

use App\Entity\Comment;
use App\Entity\CommentLike;
use App\Entity\Memory;
use App\Entity\MemoryLike;
use App\Entity\Notification;
use App\Entity\User;
use App\Enum\NotificationType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Notification>
 */
class NotificationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Notification::class);
    }

    /**
     * Notifications de commentaires non vues, de la plus récente à la plus ancienne (souvenir, commentaire
     * et acteur chargés d'un coup).
     *
     * @return Notification[]
     */
    public function findUnseenByRecipient(User $recipient): array
    {
        return $this->createQueryBuilder('n')
            ->addSelect('m', 'c', 'a')
            ->leftJoin('n.memory', 'm')
            ->leftJoin('n.comment', 'c')
            ->leftJoin('n.actor', 'a')
            ->where('n.recipient = :recipient')
            ->andWhere('n.seenAt IS NULL')
            ->setParameter('recipient', $recipient)
            ->orderBy('n.createdAt', 'DESC')
            ->addOrderBy('n.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Marque comme vues, d'un seul UPDATE, celles des ids donnés qui appartiennent au destinataire et ne le sont
     * pas déjà. Renvoie leur nombre.
     *
     * @param int[] $ids
     */
    public function markSeen(User $recipient, array $ids, \DateTimeImmutable $seenAt): int
    {
        if (!$ids) {
            return 0;
        }

        return (int) $this->createQueryBuilder('n')
            ->update()
            ->set('n.seenAt', ':seenAt')
            ->where('n.recipient = :recipient')
            ->andWhere('n.id IN (:ids)')
            ->andWhere('n.seenAt IS NULL')
            ->setParameter('seenAt', $seenAt)
            ->setParameter('recipient', $recipient)
            ->setParameter('ids', $ids)
            ->getQuery()
            ->execute();
    }

    /**
     * Notification `comment_liked` non vue d'un commentaire (au plus une)
     */
    public function findUnseenLiked(Comment|Memory $target): ?Notification
    {
        return $target instanceof Comment
            ? $this->findOneBy(['comment' => $target, 'type' => NotificationType::COMMENT_LIKED, 'seenAt' => null])
            : $this->findOneBy(['memory' => $target, 'type' => NotificationType::MEMORY_LIKED, 'seenAt' => null]);
    }

    /**
     * Souvenirs liés à une notification `memory_liked` non vue et aimés par l'utilisateur
     *
     * @return Memory[]
     */
    public function findUnseenLikedMemoriesLikedBy(User $liker): array
    {
        return $this->getEntityManager()->createQueryBuilder()
            ->select('DISTINCT m')
            ->from(Memory::class, 'm')
            ->innerJoin(Notification::class, 'n', 'WITH', 'n.memory = m AND n.type = :type AND n.seenAt IS NULL')
            ->innerJoin(MemoryLike::class, 'l', 'WITH', 'l.memory = m AND l.user = :liker')
            ->setParameter('type', NotificationType::MEMORY_LIKED)
            ->setParameter('liker', $liker)
            ->getQuery()
            ->getResult();
    }

    /** Supprime les notifications `memory_liked` (vues ou non) d'un souvenir */
    public function deleteLikedForMemory(Memory $memory): void
    {
        $this->createQueryBuilder('n')
            ->delete()
            ->where('n.memory = :memory')
            ->andWhere('n.type = :type')
            ->setParameter('memory', $memory)
            ->setParameter('type', NotificationType::MEMORY_LIKED)
            ->getQuery()
            ->execute();
    }

    /**
     * Commentaires liés à une notification `comment_liked` non vue et aimés par l'utilisateur
     *
     * @return Comment[]
     */
    public function findUnseenLikedCommentsLikedBy(User $liker): array
    {
        return $this->getEntityManager()->createQueryBuilder()
            ->select('DISTINCT c')
            ->from(Comment::class, 'c')
            ->innerJoin(Notification::class, 'n', 'WITH', 'n.comment = c AND n.type = :type AND n.seenAt IS NULL')
            ->innerJoin(CommentLike::class, 'l', 'WITH', 'l.comment = c AND l.user = :liker')
            ->setParameter('type', NotificationType::COMMENT_LIKED)
            ->setParameter('liker', $liker)
            ->getQuery()
            ->getResult();
    }

    /**
     * Supprime les notifications d'un commentaire (supprimé ou masqué mais dont la ligne reste en base)
     */
    public function deleteForComment(Comment $comment): void
    {
        $this->createQueryBuilder('n')
            ->delete()
            ->where('n.comment = :comment')
            ->setParameter('comment', $comment)
            ->getQuery()
            ->execute();
    }

    /**
     * Notifications vues avant la date donnée (les non vues ne sont jamais concernées)
     */
    public function countSeenBefore(\DateTimeImmutable $limit): int
    {
        return (int) $this->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->where('n.seenAt IS NOT NULL')
            ->andWhere('n.seenAt < :limit')
            ->setParameter('limit', $limit)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Supprime les notifications vues avant la date donnée et renvoie leur nombre
     */
    public function deleteSeenBefore(\DateTimeImmutable $limit): int
    {
        return (int) $this->createQueryBuilder('n')
            ->delete()
            ->where('n.seenAt IS NOT NULL')
            ->andWhere('n.seenAt < :limit')
            ->setParameter('limit', $limit)
            ->getQuery()
            ->execute();
    }
}
