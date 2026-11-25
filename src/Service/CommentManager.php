<?php

namespace App\Service;

use App\Entity\Comment;
use App\Entity\User;
use App\Repository\CommentLikeRepository;
use App\Repository\CommentRepository;
use App\Repository\NotificationRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Règles de suppression et de masquage des commentaires (API et back-office).
 * Les notifications d'un commentaire supprimé physiquement partent en cascade (clé étrangère).
 * Le service ne fait pas de flush : l'appelant le déclenche.
 */
class CommentManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CommentRepository $commentRepository,
        private readonly CommentLikeRepository $commentLikeRepository,
        private readonly NotificationRepository $notificationRepository,
        private readonly ModerationNotifier $moderationNotifier,
    ) {
    }

    /**
     * Si `$actor` n'est pas l'auteur, celui-ci reçoit une notification `comment_removed` (avec le motif et le message éventuels).
     * - réponse : supprimée physiquement (et son racine déjà « supprimé » avec elle s'il ne lui reste aucune réponse) ;
     * - racine sans réponse : supprimé physiquement ;
     * - racine avec réponses : conservé, contenu vidé, auteur retiré, marqué supprimé.
     */
    public function delete(Comment $comment, ?User $actor = null, ?string $reason = null, ?string $message = null): void
    {
        // Prévient l'auteur si quelqu'un d'autre (administrateur, modérateur) retire son commentaire,
        // sauf s'il l'a déjà été au masquage
        if (!$comment->isHidden()) {
            $this->moderationNotifier->commentRemoved($comment, $actor, $reason, $message);
        }

        $parent = $comment->getParent();
        if ($parent !== null) {
            $this->entityManager->remove($comment);
            if ($parent->isDeleted() && $this->commentRepository->countReplies($parent) <= 1) {
                $this->entityManager->remove($parent);
            }

            return;
        }

        if ($this->commentRepository->countReplies($comment) === 0) {
            $this->entityManager->remove($comment);

            return;
        }

        // Plus rien à aimer ni à notifier sur un commentaire vidé
        $this->commentLikeRepository->deleteForComment($comment);
        $this->notificationRepository->deleteForComment($comment);
        $comment->setContent('')->setAuthor(null)->setDeletedAt(new \DateTimeImmutable());
    }

    /**
     * Masque le commentaire (date et modérateur conservés) et prévient son auteur ; sans effet s'il l'est déjà.
     */
    public function hide(Comment $comment, ?User $actor = null, ?string $reason = null, ?string $message = null): void
    {
        if ($comment->isHidden()) {
            return;
        }

        $this->moderationNotifier->commentRemoved($comment, $actor, $reason, $message);
        $comment->setHidden(true, $actor);
        $this->notificationRepository->deleteForComment($comment);
    }

    /**
     * Affiche à nouveau un commentaire masqué et prévient son auteur (`comment_restored`) ; sans effet s'il est
     * visible. Ses likes ont été conservés ; les notifications de commentaire supprimées au masquage ne reviennent pas.
     */
    public function unhide(Comment $comment, ?User $actor = null, ?string $reason = null, ?string $message = null): void
    {
        if (!$comment->isHidden()) {
            return;
        }

        $comment->setHidden(false);
        $this->moderationNotifier->commentRestored($comment, $actor, $reason, $message);
    }
}
