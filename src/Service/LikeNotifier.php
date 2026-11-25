<?php

namespace App\Service;

use App\Entity\Comment;
use App\Entity\LikeInterface;
use App\Entity\Memory;
use App\Entity\Notification;
use App\Entity\User;
use App\Enum\NotificationType;
use App\Repository\CommentLikeRepository;
use App\Repository\LikeRepositoryInterface;
use App\Repository\MemoryLikeRepository;
use App\Repository\NotificationRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Notifications de like (`comment_liked`, `memory_liked`) : une seule non vue par contenu aimé, qui porte le nombre
 * de personnes ayant aimé depuis la dernière vue (`likesCount`) et le dernier liker (`actor`).
 *
 * Le compteur n'est jamais incrémenté à la main : il est recalculé à chaque like, unlike ou suppression de
 * compte à partir des likes d'id >= `firstLikeId` (hors auteur). Like, unlike, like ne peut donc ni créer de
 * doublon ni gonfler le compteur ; sans liker restant, la notification est supprimée. Une fois vue, la
 * notification ne bouge plus : le like suivant en crée une nouvelle (nouveau `firstLikeId`).
 *
 * Pas de notification si l'auteur est supprimé ou si le contenu n'est plus public (commentaire masqué ou supprimé),
 * ni pour son propre like. Ce qui varie selon le type de contenu est réuni dans `resolve()`.
 * Pas de flush : l'appelant flushe (like déjà flushé pour disposer de son id).
 */
class LikeNotifier
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly NotificationRepository $notificationRepository,
        private readonly CommentLikeRepository $commentLikeRepository,
        private readonly MemoryLikeRepository $memoryLikeRepository,
    ) {
    }

    /** À appeler après le flush qui a créé le like */
    public function liked(LikeInterface $like): void
    {
        $this->refresh($like->getTarget(), $like->getId());
    }

    /** À appeler après le flush qui a retiré un like */
    public function unliked(Comment|Memory $target): void
    {
        $this->refresh($target, null);
    }

    /**
     * Retire les likes d'un utilisateur qui va être supprimé et recalcule les notifications non vues concernées.
     * À appeler avant `remove($user)`.
     */
    public function forgetLiker(User $liker): void
    {
        $targets = [
            ...$this->notificationRepository->findUnseenLikedCommentsLikedBy($liker),
            ...$this->notificationRepository->findUnseenLikedMemoriesLikedBy($liker),
        ];
        $this->commentLikeRepository->deleteForUser($liker);
        $this->memoryLikeRepository->deleteForUser($liker);
        foreach ($targets as $target) {
            $this->refresh($target, null);
        }
    }

    /**
     * Ce qui diffère selon le contenu aimé : dépôt de likes, auteur (destinataire), visibilité, type de
     * notification et relations à renseigner sur la notification.
     *
     * @return array{repository: LikeRepositoryInterface, author: ?User, public: bool, type: NotificationType, memory: ?Memory, comment: ?Comment}
     */
    private function resolve(Comment|Memory $target): array
    {
        return match (true) {
            $target instanceof Comment => [
                'repository' => $this->commentLikeRepository,
                'author' => $target->getAuthor(),
                'public' => $target->isPublic(),
                'type' => NotificationType::COMMENT_LIKED,
                'memory' => $target->getMemory(),
                'comment' => $target,
            ],
            // Un souvenir n'a pas d'état masqué ou modéré : toujours public
            $target instanceof Memory => [
                'repository' => $this->memoryLikeRepository,
                'author' => $target->getUser(),
                'public' => true,
                'type' => NotificationType::MEMORY_LIKED,
                'memory' => $target,
                'comment' => null,
            ],
        };
    }

    private function refresh(Comment|Memory|null $target, ?int $newLikeId): void
    {
        if ($target === null) {
            return;
        }

        ['repository' => $likes, 'author' => $author, 'public' => $public, 'type' => $type, 'memory' => $memory, 'comment' => $comment] = $this->resolve($target);

        $notification = $this->notificationRepository->findUnseenLiked($target);
        if ($author === null || !$public) {
            $notification && $this->entityManager->remove($notification);

            return;
        }

        $fromId = $notification?->getFirstLikeId() ?? $newLikeId;
        $last = $fromId === null ? null : $likes->findLastSince($target, $fromId, $author);
        if ($last === null) {
            // Plus aucun liker (ou like de l'auteur lui-même)
            $notification && $this->entityManager->remove($notification);

            return;
        }

        if ($notification === null) {
            $notification = (new Notification($type))
                ->setRecipient($author)
                ->setMemory($memory)
                ->setComment($comment)
                ->setFirstLikeId($fromId);
            $this->entityManager->persist($notification);
        }

        $notification
            ->setActor($last->getUser())
            ->setLikesCount($likes->countSince($target, $fromId, $author))
            ->setCreatedAt($last->getCreatedAt());
    }
}
