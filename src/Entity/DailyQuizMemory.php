<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Une des cinq places du quiz d'un jour. `memory` passe à null si le souvenir est supprimé : la place est alors
 * pourvue par un autre souvenir éligible au prochain démarrage de partie (voir DailyQuizProvider).
 */
#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uniq_daily_quiz_memory_position', columns: ['quiz_id', 'position'])]
#[ORM\UniqueConstraint(name: 'uniq_daily_quiz_memory_memory', columns: ['quiz_id', 'memory_id'])]
class DailyQuizMemory
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?DailyQuiz $quiz = null;

    #[ORM\Column(type: 'smallint')]
    private int $position = 1;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Memory $memory = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getQuiz(): ?DailyQuiz
    {
        return $this->quiz;
    }

    public function setQuiz(?DailyQuiz $quiz): static
    {
        $this->quiz = $quiz;

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
}
