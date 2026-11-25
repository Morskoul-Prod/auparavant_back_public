<?php

namespace App\Service;

use App\Entity\Comment;
use App\Entity\Notification;
use App\Entity\User;
use App\Enum\NotificationType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Crée les notifications d'un nouveau commentaire. Pas de flush : l'appelant flushe avec le commentaire.
 *
 * - commentaire racine : l'auteur du souvenir ;
 * - réponse : l'auteur du commentaire racine et, si la réponse en vise une autre (`replyTo`), l'auteur de celle-ci
 *   (`reply_to_comment`), puis l'auteur du souvenir (`comment_on_memory`), sans doublon : une seule notification par
 *   destinataire, `reply_to_comment` primant ;
 * - jamais l'auteur du nouveau commentaire lui-même.
 */
class CommentNotifier
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function notify(Comment $comment): void
    {
        $actor = $comment->getAuthor();
        $memory = $comment->getMemory();
        $rootAuthor = $comment->getParent()?->getAuthor();
        $replyTo = $comment->getReplyTo();
        // Un commentaire visé supprimé ou masqué entre-temps ne notifie personne
        $replyToAuthor = $replyTo !== null && $replyTo->isPublic() ? $replyTo->getAuthor() : null;
        $memoryAuthor = $memory?->getUser();

        // Une seule notification par destinataire ; `reply_to_comment` prime sur `comment_on_memory`
        $notified = [];
        foreach ([$rootAuthor, $replyToAuthor] as $recipient) {
            if ($recipient !== null && !$this->isSame($recipient, $actor) && !$this->isIn($recipient, $notified)) {
                $this->create(NotificationType::REPLY_TO_COMMENT, $recipient, $comment);
                $notified[] = $recipient;
            }
        }

        if ($memoryAuthor !== null && !$this->isSame($memoryAuthor, $actor) && !$this->isIn($memoryAuthor, $notified)) {
            $this->create(NotificationType::COMMENT_ON_MEMORY, $memoryAuthor, $comment);
        }
    }

    /** @param User[] $users */
    private function isIn(User $user, array $users): bool
    {
        foreach ($users as $other) {
            if ($this->isSame($user, $other)) {
                return true;
            }
        }

        return false;
    }

    private function create(NotificationType $type, User $recipient, Comment $comment): void
    {
        $this->entityManager->persist(
            (new Notification($type))
                ->setRecipient($recipient)
                ->setActor($comment->getAuthor())
                ->setMemory($comment->getMemory())
                ->setComment($comment)
        );
    }

    private function isSame(?User $a, ?User $b): bool
    {
        return $a !== null && $b !== null && $a->getId() === $b->getId();
    }
}
