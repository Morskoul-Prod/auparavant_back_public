<?php

namespace App\Repository;

use App\Entity\MemoryLike;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends AbstractLikeRepository<MemoryLike>
 */
class MemoryLikeRepository extends AbstractLikeRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MemoryLike::class);
    }

    protected function targetField(): string
    {
        return 'memory';
    }

    /**
     * Nombre de likes par souvenir, en une requête groupée
     *
     * @param int[] $memoryIds
     * @return array<int, int> id du souvenir => nombre de likes (absent si zéro)
     */
    public function countByMemories(array $memoryIds): array
    {
        return $this->countByTargets($memoryIds);
    }
}
