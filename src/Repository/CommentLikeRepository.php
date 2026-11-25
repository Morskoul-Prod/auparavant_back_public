<?php

namespace App\Repository;

use App\Entity\Comment;
use App\Entity\CommentLike;
use App\Entity\User;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends AbstractLikeRepository<CommentLike>
 */
class CommentLikeRepository extends AbstractLikeRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CommentLike::class);
    }

    protected function targetField(): string
    {
        return 'comment';
    }

    /**
     * Nombre de likes par commentaire, en une requête groupée
     *
     * @param int[] $commentIds
     * @return array<int, int> id du commentaire => nombre de likes (absent si zéro)
     */
    public function countByComments(array $commentIds): array
    {
        return $this->countByTargets($commentIds);
    }

    /**
     * Parmi ces commentaires, ceux likés par l'utilisateur (une requête)
     *
     * @param int[] $commentIds
     * @return array<int, true> id du commentaire => true
     */
    public function likedByUser(User $user, array $commentIds): array
    {
        if (!$commentIds) {
            return [];
        }

        $rows = $this->createQueryBuilder('l')
            ->select('IDENTITY(l.comment) AS commentId')
            ->where('l.user = :user')
            ->andWhere('l.comment IN (:ids)')
            ->setParameter('user', $user)
            ->setParameter('ids', $commentIds)
            ->getQuery()
            ->getArrayResult();

        return array_fill_keys(array_map(fn (array $r) => (int) $r['commentId'], $rows), true);
    }

    public function deleteForComment(Comment $comment): void
    {
        $this->createQueryBuilder('l')
            ->delete()
            ->where('l.comment = :comment')
            ->setParameter('comment', $comment)
            ->getQuery()
            ->execute();
    }
}
