<?php

namespace App\Entity;

use App\Enum\QuizGameStatus;
use App\Repository\QuizGameRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Partie d'un utilisateur au quiz d'un jour : une seule par (utilisateur, jour).
 * Supprimée avec le compte (CASCADE), donc absente des classements.
 */
#[ORM\Entity(repositoryClass: QuizGameRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_quiz_game_user_date', columns: ['user_id', 'date'])]
#[ORM\Index(name: 'idx_quiz_game_date_status', columns: ['date', 'status'])]
class QuizGame
{
    public const ROUNDS_PER_GAME = 5;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private ?\DateTimeImmutable $date = null;

    #[ORM\Column(length: 20, enumType: QuizGameStatus::class)]
    private QuizGameStatus $status = QuizGameStatus::IN_PROGRESS;

    #[ORM\Column(options: ['default' => 0])]
    private int $totalScore = 0;

    #[ORM\Column]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    /** @var Collection<int, QuizRound> */
    #[ORM\OneToMany(targetEntity: QuizRound::class, mappedBy: 'game', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $rounds;

    public function __construct()
    {
        $this->startedAt = new \DateTimeImmutable();
        $this->rounds = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getDate(): ?\DateTimeImmutable
    {
        return $this->date;
    }

    public function setDate(\DateTimeImmutable $date): static
    {
        $this->date = $date;

        return $this;
    }

    public function getStatus(): QuizGameStatus
    {
        return $this->status;
    }

    public function setStatus(QuizGameStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function isFinished(): bool
    {
        return $this->status === QuizGameStatus::FINISHED;
    }

    public function getTotalScore(): int
    {
        return $this->totalScore;
    }

    public function setTotalScore(int $totalScore): static
    {
        $this->totalScore = $totalScore;

        return $this;
    }

    public function getStartedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function setFinishedAt(?\DateTimeImmutable $finishedAt): static
    {
        $this->finishedAt = $finishedAt;

        return $this;
    }

    /** @return Collection<int, QuizRound> */
    public function getRounds(): Collection
    {
        return $this->rounds;
    }

    public function addRound(QuizRound $round): static
    {
        if (!$this->rounds->contains($round)) {
            $this->rounds->add($round);
            $round->setGame($this);
        }

        return $this;
    }

    /** Première manche sans réponse, null si toutes sont répondues */
    public function getCurrentRound(): ?QuizRound
    {
        foreach ($this->rounds as $round) {
            if (!$round->isAnswered()) {
                return $round;
            }
        }

        return null;
    }

    /** @return list<QuizRound> manches déjà répondues, dans l'ordre */
    public function getAnsweredRounds(): array
    {
        return array_values($this->rounds->filter(fn (QuizRound $r) => $r->isAnswered())->toArray());
    }
}
