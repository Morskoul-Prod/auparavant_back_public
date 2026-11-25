<?php

namespace App\Entity;

use App\Enum\ReportReason;
use App\Enum\ReportStatus;
use App\Enum\ReportTargetType;
use App\Repository\ReportRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Signalement d'un contenu (commentaire, et plus tard souvenir ou photo) par un utilisateur.
 * La cible est désignée par `targetType` + `targetId` (pas de clé étrangère : modèle générique) ;
 * un utilisateur ne peut signaler une même cible qu'une fois.
 */
#[ORM\Entity(repositoryClass: ReportRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_report_reporter_target', columns: ['reporter_id', 'target_type', 'target_id'])]
#[ORM\Index(columns: ['status', 'target_type', 'target_id'])]
class Report
{
    public const MAX_DETAILS_LENGTH = 500;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 10, enumType: ReportTargetType::class)]
    private ReportTargetType $targetType;

    #[ORM\Column]
    private int $targetId;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $reporter = null;

    #[ORM\Column(length: 10, enumType: ReportReason::class)]
    private ReportReason $reason;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $details = null;

    #[ORM\Column(length: 10, enumType: ReportStatus::class, options: ['default' => 'open'])]
    private ReportStatus $status = ReportStatus::OPEN;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $handledAt = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $handledBy = null;

    public function __construct(ReportTargetType $targetType, int $targetId, ReportReason $reason)
    {
        $this->targetType = $targetType;
        $this->targetId = $targetId;
        $this->reason = $reason;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTargetType(): ReportTargetType
    {
        return $this->targetType;
    }

    public function getTargetId(): int
    {
        return $this->targetId;
    }

    public function getReporter(): ?User
    {
        return $this->reporter;
    }

    public function setReporter(?User $reporter): static
    {
        $this->reporter = $reporter;

        return $this;
    }

    public function getReason(): ReportReason
    {
        return $this->reason;
    }

    public function getDetails(): ?string
    {
        return $this->details;
    }

    public function setDetails(?string $details): static
    {
        $this->details = $details;

        return $this;
    }

    public function getStatus(): ReportStatus
    {
        return $this->status;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getHandledAt(): ?\DateTimeImmutable
    {
        return $this->handledAt;
    }

    public function getHandledBy(): ?User
    {
        return $this->handledBy;
    }

    public function isOpen(): bool
    {
        return $this->status === ReportStatus::OPEN;
    }

    public function markHandled(?User $by): static
    {
        $this->status = ReportStatus::HANDLED;
        $this->handledAt = new \DateTimeImmutable();
        $this->handledBy = $by;

        return $this;
    }
}
