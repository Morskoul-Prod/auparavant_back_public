<?php

namespace App\Repository;

use App\Entity\Comment;
use App\Entity\LikeInterface;
use App\Entity\Memory;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;

/**
 * Implémentation commune de `LikeRepositoryInterface` ; chaque dépôt indique seulement le champ qui porte le contenu aimé.
 *
 * @template T of LikeInterface
 * @extends ServiceEntityRepository<T>
 */
abstract class AbstractLikeRepository extends ServiceEntityRepository implements LikeRepositoryInterface
{
    /** Champ de l'entité qui référence le contenu aimé (`comment`, `memory`) */
    abstract protected function targetField(): string;

    public function countForTarget(Comment|Memory $target): int
    {
        return $this->count([$this->targetField() => $target]);
    }

    public function countSince(Comment|Memory $target, int $fromId, User $excludedAuthor): int
    {
        return (int) $this->sinceQuery($target, $fromId, $excludedAuthor)
            ->select('COUNT(l.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findLastSince(Comment|Memory $target, int $fromId, User $excludedAuthor): ?LikeInterface
    {
        return $this->sinceQuery($target, $fromId, $excludedAuthor)
            ->addSelect('u')
            ->innerJoin('l.user', 'u')
            ->orderBy('l.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Nombre de likes par contenu, en une requête groupée
     *
     * @param int[] $targetIds
     * @return array<int, int> id du contenu => nombre de likes (absent si zéro)
     */
    public function countByTargets(array $targetIds): array
    {
        if (!$targetIds) {
            return [];
        }

        $rows = $this->createQueryBuilder('l')
            ->select('IDENTITY(l.' . $this->targetField() . ') AS targetId', 'COUNT(l.id) AS total')
            ->where('l.' . $this->targetField() . ' IN (:ids)')
            ->setParameter('ids', $targetIds)
            ->groupBy('l.' . $this->targetField())
            ->getQuery()
            ->getArrayResult();

        return array_column(array_map(fn (array $r) => [(int) $r['targetId'], (int) $r['total']], $rows), 1, 0);
    }

    public function deleteForUser(User $user): void
    {
        $this->createQueryBuilder('l')
            ->delete()
            ->where('l.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->execute();
    }

    public function findLikers(Comment|Memory $target, int $limit): array
    {
        $likes = $this->createQueryBuilder('l')
            ->addSelect('u')
            ->innerJoin('l.user', 'u')
            ->where('l.' . $this->targetField() . ' = :target')
            ->setParameter('target', $target)
            ->orderBy('l.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return array_map(fn (LikeInterface $like) => $like->getUser(), $likes);
    }

    private function sinceQuery(Comment|Memory $target, int $fromId, User $excludedAuthor): QueryBuilder
    {
        return $this->createQueryBuilder('l')
            ->where('l.' . $this->targetField() . ' = :target')
            ->andWhere('l.id >= :fromId')
            ->andWhere('l.user != :excluded')
            ->setParameter('target', $target)
            ->setParameter('fromId', $fromId)
            ->setParameter('excluded', $excludedAuthor);
    }
}
