<?php

namespace App\EventListener;

use App\Entity\Memory;
use App\Repository\NotificationRepository;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Events;

/**
 * Supprime les notifications `memory_liked` d'un souvenir qui disparaît, quelle que soit la voie (suppression
 * directe, d'un lieu ou d'un utilisateur) : leur lien vers le souvenir est en SET NULL, elles resteraient orphelines.
 * Les likes eux-mêmes partent en cascade (clé étrangère).
 */
#[AsEntityListener(event: Events::preRemove, entity: Memory::class)]
class MemoryLikeNotificationCleaner
{
    public function __construct(private readonly NotificationRepository $notificationRepository)
    {
    }

    public function preRemove(Memory $memory): void
    {
        $this->notificationRepository->deleteLikedForMemory($memory);
    }
}
