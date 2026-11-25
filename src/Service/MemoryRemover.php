<?php

namespace App\Service;

use App\Entity\Memory;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Suppression d'un souvenir : ses photos (fichiers compris), commentaires et notifications partent avec lui,
 * et sa Location / son Place deviennent orphelins s'ils n'ont plus de souvenir (OrphanLocationCleaner).
 * Pas de flush : l'appelant le déclenche.
 */
class MemoryRemover
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly OrphanLocationCleaner $orphanLocationCleaner,
        private readonly ModerationNotifier $moderationNotifier,
    ) {
    }

    /**
     * Si `$actor` n'est pas l'auteur du souvenir (administrateur, modérateur), celui-ci reçoit une notification
     * `memory_deleted` avec le titre du souvenir, le motif et le message éventuels.
     */
    public function remove(Memory $memory, ?User $actor = null, ?string $reason = null, ?string $message = null): void
    {
        $this->moderationNotifier->memoryDeleted($memory, $actor, $reason, $message);
        $this->orphanLocationCleaner->cleanAfterRemoval([$memory]);
        $this->entityManager->remove($memory);
    }
}
