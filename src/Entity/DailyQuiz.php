<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Quiz d'un jour (Europe/Paris) : les mêmes souvenirs pour tous les joueurs.
 *
 * La date est unique. Les lignes (DailyQuiz et DailyQuizMemory) sont écrites par DailyQuizProvider en SQL direct
 * pour que la collision de deux créations simultanées reste récupérable (une violation d'unicité ne ferme pas le
 * gestionnaire d'entités) ; les entités décrivent le schéma.
 */
#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uniq_daily_quiz_date', columns: ['date'])]
class DailyQuiz
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private ?\DateTimeImmutable $date = null;

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

    public function getDate(): ?\DateTimeImmutable
    {
        return $this->date;
    }

    public function setDate(\DateTimeImmutable $date): static
    {
        $this->date = $date;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
