<?php

namespace App\Entity;

use App\Repository\QuizRoundRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Manche d'une partie. La vérité (position, année, titre, photo) est un instantané pris à la création de la partie :
 * la partie va à son terme même si le souvenir est supprimé ensuite (`memory` passe alors à null).
 *
 * Tant que `answeredAt` est null, rien d'autre que `position` et la photo ne doit sortir de l'API.
 */
#[ORM\Entity(repositoryClass: QuizRoundRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_quiz_round_game_position', columns: ['game_id', 'position'])]
class QuizRound
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'rounds')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?QuizGame $game = null;

    /** De 1 à QuizGame::ROUNDS_PER_GAME */
    #[ORM\Column(type: 'smallint')]
    private int $position = 1;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Memory $memory = null;

    // --- Instantané de la vérité ---

    #[ORM\Column(length: 255)]
    private string $memoryTitle = '';

    /** Nom de fichier de la photo principale (jamais exposé : l'API passe par QuizPictures) */
    #[ORM\Column(length: 255)]
    private string $pictureName = '';

    #[ORM\Column]
    private float $trueLat = 0.0;

    #[ORM\Column]
    private float $trueLng = 0.0;

    #[ORM\Column]
    private int $trueYear = 0;

    // --- Réponse du joueur ---

    #[ORM\Column(nullable: true)]
    private ?float $answerLat = null;

    #[ORM\Column(nullable: true)]
    private ?float $answerLng = null;

    #[ORM\Column(nullable: true)]
    private ?int $answerYear = null;

    #[ORM\Column(nullable: true)]
    private ?float $distanceKm = null;

    #[ORM\Column(nullable: true)]
    private ?int $yearDiff = null;

    #[ORM\Column(nullable: true)]
    private ?int $locationScore = null;

    #[ORM\Column(nullable: true)]
    private ?int $yearScore = null;

    #[ORM\Column(nullable: true)]
    private ?int $score = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $answeredAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getGame(): ?QuizGame
    {
        return $this->game;
    }

    public function setGame(?QuizGame $game): static
    {
        $this->game = $game;

        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

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

    public function getMemoryTitle(): string
    {
        return $this->memoryTitle;
    }

    public function setMemoryTitle(string $memoryTitle): static
    {
        $this->memoryTitle = $memoryTitle;

        return $this;
    }

    public function getPictureName(): string
    {
        return $this->pictureName;
    }

    public function setPictureName(string $pictureName): static
    {
        $this->pictureName = $pictureName;

        return $this;
    }

    public function getTrueLat(): float
    {
        return $this->trueLat;
    }

    public function setTrueLat(float $trueLat): static
    {
        $this->trueLat = $trueLat;

        return $this;
    }

    public function getTrueLng(): float
    {
        return $this->trueLng;
    }

    public function setTrueLng(float $trueLng): static
    {
        $this->trueLng = $trueLng;

        return $this;
    }

    public function getTrueYear(): int
    {
        return $this->trueYear;
    }

    public function setTrueYear(int $trueYear): static
    {
        $this->trueYear = $trueYear;

        return $this;
    }

    public function getAnswerLat(): ?float
    {
        return $this->answerLat;
    }

    public function getAnswerLng(): ?float
    {
        return $this->answerLng;
    }

    public function getAnswerYear(): ?int
    {
        return $this->answerYear;
    }

    public function getDistanceKm(): ?float
    {
        return $this->distanceKm;
    }

    public function getYearDiff(): ?int
    {
        return $this->yearDiff;
    }

    public function getLocationScore(): ?int
    {
        return $this->locationScore;
    }

    public function getYearScore(): ?int
    {
        return $this->yearScore;
    }

    public function getScore(): ?int
    {
        return $this->score;
    }

    public function getAnsweredAt(): ?\DateTimeImmutable
    {
        return $this->answeredAt;
    }

    public function isAnswered(): bool
    {
        return $this->answeredAt !== null;
    }
}
