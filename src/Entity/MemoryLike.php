<?php

namespace App\Entity;

use App\Repository\MemoryLikeRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * « J'aime » d'un utilisateur sur un souvenir (un seul par couple souvenir / utilisateur).
 */
#[ORM\Entity(repositoryClass: MemoryLikeRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_memory_like_memory_user', columns: ['memory_id', 'user_id'])]
class MemoryLike implements LikeInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Memory $memory = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getTarget(): Comment|Memory|null
    {
        return $this->getMemory();
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
