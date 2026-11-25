<?php

namespace App\Service;

use App\Entity\Comment;
use App\Entity\Memory;
use App\Entity\Notification;
use App\Entity\Picture;
use App\Entity\Report;
use App\Entity\User;
use App\Enum\NotificationType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Prévient l'auteur d'un contenu retiré par quelqu'un d'autre (administrateur ou modérateur). Pas de flush.
 *
 * Ces notifications doivent survivre au contenu retiré : elles ne le référencent donc pas (`comment` et, pour un
 * souvenir supprimé, `memory` restent à null ; seul un commentaire rétabli est lié, puisqu'il existe toujours) et en gardent un instantané (titre du souvenir, extrait).
 * L'identité du modérateur n'est pas conservée.
 */
class ModerationNotifier
{
    public const MAX_REASON_LENGTH = 200;
    public const MAX_MESSAGE_LENGTH = 500;
    private const EXCERPT_LENGTH = 100;

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /** À appeler avant la suppression du souvenir */
    public function memoryDeleted(Memory $memory, ?User $actor, ?string $reason, ?string $message = null): void
    {
        $author = $memory->getUser();
        if (!$this->isOtherUser($author, $actor)) {
            return;
        }

        $this->entityManager->persist(
            (new Notification(NotificationType::MEMORY_DELETED))
                ->setRecipient($author)
                ->setMemoryTitle($memory->getTitle())
                ->setReason($reason)
                ->setMessage($message)
        );
    }

    /**
     * À appeler à la validation d'une photo en attente : prévient celui qui l'a proposée (`uploadedBy`), sauf s'il la
     * valide lui-même. Le souvenir est référencé (`memoryId`, `memoryTitle`) ; ni commentaire, ni motif, ni modérateur.
     */
    public function pictureApproved(Picture $picture, ?User $actor): void
    {
        $uploader = $picture->getUploadedBy();
        if (!$this->isOtherUser($uploader, $actor)) {
            return;
        }

        $memory = $picture->getMemory();
        $this->entityManager->persist(
            (new Notification(NotificationType::PICTURE_APPROVED))
                ->setRecipient($uploader)
                ->setMemory($memory)
                ->setMemoryTitle($memory?->getTitle())
        );
    }

    /**
     * À appeler avant la suppression de la photo : prévient celui qui l'a proposée (`uploadedBy`).
     * La notification garde le souvenir (`memoryId`, `memoryTitle`) mais n'a ni commentaire ni extrait.
     */
    public function pictureRemoved(Picture $picture, ?User $actor, ?string $reason, ?string $message = null, ?Memory $memory = null): void
    {
        $uploader = $picture->getUploadedBy();
        if (!$this->isOtherUser($uploader, $actor)) {
            return;
        }

        // `$memory` : souvenir d'origine quand la photo en a déjà été détachée (formulaire du back-office)
        $memory ??= $picture->getMemory();
        $this->entityManager->persist(
            (new Notification(NotificationType::PICTURE_REMOVED))
                ->setRecipient($uploader)
                ->setMemory($memory)
                ->setMemoryTitle($memory?->getTitle())
                ->setReason($reason)
                ->setMessage($message)
        );
    }

    /**
     * À appeler à la création d'une proposition de remplacement : prévient celui qui a déposé la photo actuelle
     * en place, sauf s'il est le proposant ou inconnu (anciennes photos).
     */
    public function currentPictureReplacementProposed(Picture $replacement): void
    {
        $this->currentPictureNotification(NotificationType::CURRENT_PICTURE_REPLACEMENT_PROPOSED, $replacement);
    }

    /**
     * À appeler avant la suppression de l'ancienne photo actuelle, à la validation du remplaçant :
     * prévient son déposant, sauf s'il est le proposant ou inconnu.
     */
    public function currentPictureReplaced(Picture $replacement): void
    {
        $this->currentPictureNotification(NotificationType::CURRENT_PICTURE_REPLACED, $replacement);
    }

    private function currentPictureNotification(NotificationType $type, Picture $replacement): void
    {
        $proposer = $replacement->getUploadedBy();
        $recipient = $replacement->getReplaces()?->getUploadedBy();
        if ($proposer === null || !$this->isOtherUser($recipient, $proposer)) {
            return;
        }

        $memory = $replacement->getMemory();
        $this->entityManager->persist(
            (new Notification($type))
                ->setRecipient($recipient)
                ->setActor($proposer)
                ->setMemory($memory)
                ->setMemoryTitle($memory?->getTitle())
        );
    }

    /**
     * Prévient l'auteur d'un signalement que la modération l'a examiné. Volontairement identique quelle que soit
     * l'issue (ignoré, masqué, supprimé) : aucun contenu, aucun lien, pas de modérateur. À appeler une seule fois,
     * quand le signalement ouvert passe à « traité » ; rien si son auteur n'existe plus.
     */
    public function reportReviewed(Report $report): void
    {
        $reporter = $report->getReporter();
        if ($reporter === null) {
            return;
        }

        $this->entityManager->persist((new Notification(NotificationType::REPORT_REVIEWED))->setRecipient($reporter));
    }

    /** À appeler avant la suppression ou le masquage du commentaire */
    public function commentRemoved(Comment $comment, ?User $actor, ?string $reason, ?string $message = null): void
    {
        $this->commentNotification(NotificationType::COMMENT_REMOVED, $comment, $actor, $reason, $message, false);
    }

    /**
     * Commentaire masqué puis rétabli : la notification pointe vers le commentaire, de nouveau visible
     */
    public function commentRestored(Comment $comment, ?User $actor, ?string $reason = null, ?string $message = null): void
    {
        $this->commentNotification(NotificationType::COMMENT_RESTORED, $comment, $actor, $reason, $message, true);
    }

    private function commentNotification(NotificationType $type, Comment $comment, ?User $actor, ?string $reason, ?string $message, bool $linkComment): void
    {
        $author = $comment->getAuthor();
        if (!$this->isOtherUser($author, $actor)) {
            return;
        }

        $content = $comment->getContent();
        $this->entityManager->persist(
            (new Notification($type))
                ->setRecipient($author)
                ->setMemory($comment->getMemory())
                ->setComment($linkComment ? $comment : null)
                ->setMemoryTitle($comment->getMemory()?->getTitle())
                ->setExcerpt(mb_strlen($content) > self::EXCERPT_LENGTH ? mb_substr($content, 0, self::EXCERPT_LENGTH) . '…' : $content)
                ->setReason($reason)
                ->setMessage($message)
        );
    }

    /** Pas de notification quand on retire son propre contenu, ni quand l'auteur n'existe plus */
    private function isOtherUser(?User $author, ?User $actor): bool
    {
        return $author !== null && ($actor === null || $author->getId() !== $actor->getId());
    }

    /**
     * Objet (motif court) saisi par le modérateur : null s'il est vide ou n'est pas un texte.
     * Appeler `isValidReason()` avant pour refuser un motif trop long.
     */
    public static function normalizeReason(mixed $reason): ?string
    {
        return self::normalize($reason);
    }

    public static function isValidReason(mixed $reason): bool
    {
        return self::isValid($reason, self::MAX_REASON_LENGTH);
    }

    /**
     * Message libre du modérateur, en plus de l'objet : null s'il est vide ou n'est pas un texte.
     * Appeler `isValidMessage()` avant pour refuser un message trop long.
     */
    public static function normalizeMessage(mixed $message): ?string
    {
        return self::normalize($message);
    }

    public static function isValidMessage(mixed $message): bool
    {
        return self::isValid($message, self::MAX_MESSAGE_LENGTH);
    }

    /** Message d'erreur lisible si l'objet ou le message est trop long, null s'ils sont valides */
    public static function validationError(mixed $reason, mixed $message): ?string
    {
        if (!self::isValidReason($reason)) {
            return sprintf('Le motif ne peut pas dépasser %d caractères.', self::MAX_REASON_LENGTH);
        }
        if (!self::isValidMessage($message)) {
            return sprintf('Le message ne peut pas dépasser %d caractères.', self::MAX_MESSAGE_LENGTH);
        }

        return null;
    }

    private static function normalize(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value !== '' ? $value : null;
    }

    private static function isValid(mixed $value, int $max): bool
    {
        return $value === null || (is_string($value) && mb_strlen(trim($value)) <= $max);
    }
}
