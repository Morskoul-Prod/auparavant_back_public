<?php

namespace App\Service;

use App\Entity\Memory;
use App\Entity\Notification;
use App\Entity\User;
use App\Enum\NotificationType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Notifie les utilisateurs ayant déjà posté un souvenir sur la localisation d'un nouveau souvenir (`location_new_memory`).
 * Une notification par destinataire, jamais pour l'auteur du nouveau souvenir. Pas de flush : l'appelant flushe avec le souvenir.
 * À appeler avant le flush (le nouveau souvenir n'est pas encore en base, il ne peut donc pas se retrouver parmi les existants).
 */
class LocationMemoryNotifier
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function notify(Memory $memory): void
    {
        $location = $memory->getLocation();
        $author = $memory->getUser();
        if ($location === null || $location->getId() === null || $author === null) {
            return;
        }

        /** @var User[] $recipients */
        $recipients = $this->entityManager->createQueryBuilder()
            ->select('DISTINCT u')
            ->from(User::class, 'u')
            ->innerJoin(Memory::class, 'm', 'WITH', 'm.user = u')
            ->where('m.location = :location')
            ->andWhere('u != :author')
            ->setParameter('location', $location)
            ->setParameter('author', $author)
            ->getQuery()
            ->getResult();

        foreach ($recipients as $recipient) {
            $this->entityManager->persist(
                (new Notification(NotificationType::LOCATION_NEW_MEMORY))
                    ->setRecipient($recipient)
                    ->setActor($author)
                    ->setMemory($memory)
                    ->setMemoryTitle($memory->getTitle())
            );
        }
    }
}
