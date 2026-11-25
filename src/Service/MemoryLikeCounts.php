<?php

namespace App\Service;

use App\Entity\Memory;
use App\Repository\CommentRepository;
use App\Repository\MemoryLikeRepository;

/**
 * Nombre de likes et de commentaires publics des souvenirs d'une liste, en requêtes groupées (pas de N+1).
 *
 * Deux usages : `hydrate()` renseigne `Memory::likesCount` et `Memory::commentsCount` pour les listes sérialisées avec le groupe
 * `get_memory_likes` ; `countsFor()` renvoie la table id => total pour les listes construites à la main
 * (`MemoryProcessor`) ; `commentCountsFor()` fait de même pour les commentaires publics (ni masqués ni supprimés).
 */
class MemoryLikeCounts
{
    public function __construct(
        private readonly MemoryLikeRepository $memoryLikeRepository,
        private readonly CommentRepository $commentRepository,
    ) {
    }

    /**
     * @param iterable<Memory> $memories
     * @return array<int, int> id du souvenir => nombre de commentaires publics (0 si aucun)
     */
    public function commentCountsFor(iterable $memories): array
    {
        $ids = [];
        foreach ($memories as $memory) {
            $ids[] = $memory->getId();
        }

        return array_replace(array_fill_keys($ids, 0), $this->commentRepository->countPublicByMemories($ids));
    }

    /**
     * @param iterable<Memory> $memories
     * @return array<int, int> id du souvenir => nombre de likes (0 si aucun)
     */
    public function countsFor(iterable $memories): array
    {
        $ids = [];
        foreach ($memories as $memory) {
            $ids[] = $memory->getId();
        }
        $counts = $this->memoryLikeRepository->countByMemories($ids);

        return array_replace(array_fill_keys($ids, 0), $counts);
    }

    /**
     * Renseigne `likesCount` sur chaque souvenir
     *
     * @param iterable<Memory> $memories
     * @return array<Memory> la même liste, pour chaîner
     */
    public function hydrate(iterable $memories): array
    {
        $memories = is_array($memories) ? $memories : iterator_to_array($memories);
        $counts = $this->countsFor($memories);
        $commentCounts = $this->commentCountsFor($memories);
        foreach ($memories as $memory) {
            $memory->setLikesCount($counts[$memory->getId()]);
            $memory->setCommentsCount($commentCounts[$memory->getId()]);
        }

        return $memories;
    }
}
