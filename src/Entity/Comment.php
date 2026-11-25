<?php

namespace App\Entity;

use App\Enum\CommentStatus;
use App\Repository\CommentRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Commentaire d'un souvenir. Un seul niveau de réponses : `parent` désigne toujours un commentaire racine.
 *
 * Supprimé : un racine qui a des réponses est conservé (contenu vidé, auteur retiré, `deletedAt` renseigné) ;
 * tout autre commentaire est supprimé physiquement.
 */
#[ORM\Entity(repositoryClass: CommentRepository::class)]
#[ORM\Index(columns: ['created_at'])]
class Comment
{
    public const MAX_LENGTH = 1000;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Vide ('') pour un commentaire supprimé dont on conserve la ligne (racine avec réponses) */
    #[ORM\Column(type: Types::TEXT)]
    private string $content = '';

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\ManyToOne(inversedBy: 'comments')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Memory $memory = null;

    /** Null si l'utilisateur a été supprimé (commentaire anonymisé) ou si le commentaire est supprimé */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $author = null;

    #[ORM\ManyToOne(targetEntity: self::class, inversedBy: 'replies')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Comment $parent = null;

    /**
     * Réponse du même fil visée par celle-ci (le `@Prénom N.` du front) ; null pour une réponse directe au racine
     * (déjà couverte par `parent`) ou si le commentaire visé a été supprimé.
     */
    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Comment $replyTo = null;

    /** @var Collection<int, Comment> */
    #[ORM\OneToMany(mappedBy: 'parent', targetEntity: self::class)]
    #[ORM\OrderBy(['createdAt' => 'ASC', 'id' => 'ASC'])]
    private Collection $replies;

    #[ORM\Column(length: 10, enumType: CommentStatus::class, options: ['default' => 'visible'])]
    private CommentStatus $status = CommentStatus::VISIBLE;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    /** Date du masquage par la modération (null si le commentaire n'est pas masqué) */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $hiddenAt = null;

    /** Modérateur ou administrateur qui a masqué le commentaire ; null s'il n'existe plus */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $hiddenBy = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->replies = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function setContent(string $content): static
    {
        $this->content = $content;

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

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;

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

    public function getAuthor(): ?User
    {
        return $this->author;
    }

    public function setAuthor(?User $author): static
    {
        $this->author = $author;

        return $this;
    }

    public function getParent(): ?Comment
    {
        return $this->parent;
    }

    public function setParent(?Comment $parent): static
    {
        $this->parent = $parent;

        return $this;
    }

    public function getReplyTo(): ?Comment
    {
        return $this->replyTo;
    }

    public function setReplyTo(?Comment $replyTo): static
    {
        $this->replyTo = $replyTo;

        return $this;
    }

    /**
     * @return Collection<int, Comment>
     */
    public function getReplies(): Collection
    {
        return $this->replies;
    }

    public function getStatus(): CommentStatus
    {
        return $this->status;
    }

    public function setStatus(CommentStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getDeletedAt(): ?\DateTimeImmutable
    {
        return $this->deletedAt;
    }

    public function setDeletedAt(?\DateTimeImmutable $deletedAt): static
    {
        $this->deletedAt = $deletedAt;

        return $this;
    }

    public function getHiddenAt(): ?\DateTimeImmutable
    {
        return $this->hiddenAt;
    }

    public function getHiddenBy(): ?User
    {
        return $this->hiddenBy;
    }

    /** Masque (avec date et auteur de l'action) ou affiche à nouveau le commentaire */
    public function setHidden(bool $hidden, ?User $by = null): static
    {
        $this->status = $hidden ? CommentStatus::HIDDEN : CommentStatus::VISIBLE;
        $this->hiddenAt = $hidden ? new \DateTimeImmutable() : null;
        $this->hiddenBy = $hidden ? $by : null;

        return $this;
    }

    public function isDeleted(): bool
    {
        return $this->deletedAt !== null;
    }

    public function isHidden(): bool
    {
        return $this->status === CommentStatus::HIDDEN;
    }

    public function isReply(): bool
    {
        return $this->parent !== null;
    }

    /** Visible publiquement avec son contenu (ni masqué ni supprimé) */
    public function isPublic(): bool
    {
        return !$this->isDeleted() && !$this->isHidden();
    }
}
