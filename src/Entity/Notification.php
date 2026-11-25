<?php

namespace App\Entity;

use App\Enum\NotificationType;
use App\Repository\NotificationRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Notification de commentaire adressée à un utilisateur (le front les récupère par polling).
 * Supprimée avec le destinataire ou le commentaire concerné (celles de modération ne pointent plus vers le contenu retiré).
 */
#[ORM\Entity(repositoryClass: NotificationRepository::class)]
#[ORM\Index(columns: ['recipient_id', 'seen_at'])]
class Notification
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $recipient = null;

    #[ORM\Column(length: 50, enumType: NotificationType::class)]
    private NotificationType $type;

    /** Auteur du commentaire à l'origine de la notification ; null si l'utilisateur a été supprimé */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $actor = null;

    /** Null pour `memory_deleted` (le souvenir n'existe plus), ou si le souvenir a été supprimé depuis */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Memory $memory = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Comment $comment = null;

    /** Titre du souvenir au moment de la notification : seule trace d'un souvenir supprimé */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $memoryTitle = null;

    /** Début du commentaire retiré (notifications de modération ; sinon l'extrait est lu sur le commentaire) */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $excerpt = null;

    /** Motif court indiqué par la modération (facultatif) */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $reason = null;

    /** Message libre de la modération, en plus du motif (facultatif, 500 caractères au plus) */
    #[ORM\Column(length: 500, nullable: true)]
    private ?string $message = null;

    /** `comment_liked` : nombre de personnes (hors auteur) ayant aimé le commentaire depuis la dernière vue ; null sinon */
    #[ORM\Column(nullable: true)]
    private ?int $likesCount = null;

    /** `comment_liked` : id du premier `CommentLike` pris en compte ; seuls les likes d'id supérieur ou égal comptent (pas de clé étrangère) */
    #[ORM\Column(nullable: true)]
    private ?int $firstLikeId = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $seenAt = null;

    public function __construct(NotificationType $type)
    {
        $this->type = $type;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRecipient(): ?User
    {
        return $this->recipient;
    }

    public function setRecipient(?User $recipient): static
    {
        $this->recipient = $recipient;

        return $this;
    }

    public function getType(): NotificationType
    {
        return $this->type;
    }

    public function getActor(): ?User
    {
        return $this->actor;
    }

    public function setActor(?User $actor): static
    {
        $this->actor = $actor;

        return $this;
    }

    public function getMemory(): ?Memory
    {
        return $this->memory;
    }

    public function setMemory(?Memory $memory): static
    {
        $this->memory = $memory;

        return $this;
    }

    public function getComment(): ?Comment
    {
        return $this->comment;
    }

    public function setComment(?Comment $comment): static
    {
        $this->comment = $comment;

        return $this;
    }

    public function getMemoryTitle(): ?string
    {
        return $this->memoryTitle;
    }

    public function setMemoryTitle(?string $memoryTitle): static
    {
        $this->memoryTitle = $memoryTitle;

        return $this;
    }

    public function getExcerpt(): ?string
    {
        return $this->excerpt;
    }

    public function setExcerpt(?string $excerpt): static
    {
        $this->excerpt = $excerpt;

        return $this;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    public function setReason(?string $reason): static
    {
        $this->reason = $reason;

        return $this;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function setMessage(?string $message): static
    {
        $this->message = $message;

        return $this;
    }

    public function getLikesCount(): ?int
    {
        return $this->likesCount;
    }

    public function setLikesCount(?int $likesCount): static
    {
        $this->likesCount = $likesCount;

        return $this;
    }

    public function getFirstLikeId(): ?int
    {
        return $this->firstLikeId;
    }

    public function setFirstLikeId(?int $firstLikeId): static
    {
        $this->firstLikeId = $firstLikeId;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getSeenAt(): ?\DateTimeImmutable
    {
        return $this->seenAt;
    }

    public function setSeenAt(?\DateTimeImmutable $seenAt): static
    {
        $this->seenAt = $seenAt;

        return $this;
    }
}
