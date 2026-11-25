<?php

namespace App\Service;

use App\Entity\Memory;
use App\Entity\QuizGame;
use App\Exception\QuizException;
use App\Repository\MemoryRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Souvenirs du quiz d'un jour : créés à la première demande, identiques pour tous les joueurs.
 *
 * Écriture en SQL direct, une place à la fois : la collision de deux créations simultanées (unicité de la date, de
 * la place ou du souvenir) est une violation d'unicité récupérable, qui laisse le gestionnaire d'entités utilisable.
 * Le perdant relit simplement ce que le gagnant a écrit et ne comble que les places encore vides.
 *
 * Une place dont le souvenir a été supprimé (memory_id passé à null) ou n'est plus éligible est pourvue par un
 * autre souvenir éligible. Les parties déjà démarrées ne sont pas touchées : elles portent leur propre instantané.
 */
class DailyQuizProvider
{
    private const MAX_ATTEMPTS = 3;

    private Connection $connection;

    public function __construct(
        EntityManagerInterface $em,
        private readonly QuizMemorySelector $selector,
        private readonly MemoryRepository $memoryRepository,
    ) {
        $this->connection = $em->getConnection();
    }

    /**
     * @return list<Memory> les souvenirs du jour, dans l'ordre des manches
     * @throws QuizException s'il n'y a pas assez de souvenirs éligibles
     */
    public function memoriesFor(\DateTimeImmutable $day): array
    {
        $date = $day->format('Y-m-d');
        $quizId = $this->findQuizId($date);

        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; ++$attempt) {
            $slots = $quizId !== null ? $this->readSlots($quizId) : [];
            $this->releaseIneligible($slots);

            $missing = array_values(array_filter(
                range(1, QuizGame::ROUNDS_PER_GAME),
                fn (int $position) => ($slots[$position]['memoryId'] ?? null) === null,
            ));
            if (!$missing) {
                break;
            }

            $taken = array_values(array_filter(array_column($slots, 'memoryId')));
            $chosen = $this->selector->pick(count($missing), $taken); // lève avant toute écriture si le vivier est trop petit

            try {
                $quizId ??= $this->insertQuiz($date);
                foreach ($missing as $i => $position) {
                    $this->fillSlot($quizId, $position, $chosen[$i]->getId(), $slots[$position]['id'] ?? null);
                }
            } catch (UniqueConstraintViolationException) {
                // Une requête concurrente a écrit en même temps : on relit et on comble ce qui manque encore
                $quizId ??= $this->findQuizId($date);
            }
        }

        $slots = $quizId !== null ? $this->readSlots($quizId) : [];
        $ids = [];
        foreach (range(1, QuizGame::ROUNDS_PER_GAME) as $position) {
            if (($slots[$position]['memoryId'] ?? null) === null) {
                throw QuizException::notEnoughMemories();
            }
            $ids[] = $slots[$position]['memoryId'];
        }

        $memories = [];
        foreach ($this->memoryRepository->findBy(['id' => $ids]) as $memory) {
            $memories[$memory->getId()] = $memory;
        }

        return array_map(fn (int $id) => $memories[$id] ?? throw QuizException::notEnoughMemories(), $ids);
    }

    private function findQuizId(string $date): ?int
    {
        $id = $this->connection->fetchOne('SELECT id FROM daily_quiz WHERE date = ?', [$date]);

        return $id === false ? null : (int) $id;
    }

    /** @throws UniqueConstraintViolationException si le quiz du jour existe déjà */
    private function insertQuiz(string $date): int
    {
        $this->connection->insert('daily_quiz', ['date' => $date, 'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s')]);

        return (int) $this->connection->lastInsertId();
    }

    /** @return array<int, array{id: int, memoryId: int|null}> places existantes, indexées par position */
    private function readSlots(int $quizId): array
    {
        $slots = [];
        foreach ($this->connection->fetchAllAssociative('SELECT id, position, memory_id FROM daily_quiz_memory WHERE quiz_id = ?', [$quizId]) as $row) {
            $slots[(int) $row['position']] = ['id' => (int) $row['id'], 'memoryId' => $row['memory_id'] !== null ? (int) $row['memory_id'] : null];
        }

        return $slots;
    }

    /**
     * Vide les places dont le souvenir n'est plus éligible (photo retirée, coordonnées perdues…)
     *
     * @param array<int, array{id: int, memoryId: int|null}> $slots modifié en conséquence
     */
    private function releaseIneligible(array &$slots): void
    {
        $ids = array_values(array_filter(array_column($slots, 'memoryId')));
        if (!$ids) {
            return;
        }
        $eligible = [];
        foreach ($this->memoryRepository->findBy(['id' => $ids]) as $memory) {
            $eligible[$memory->getId()] = $this->selector->isEligible($memory);
        }
        foreach ($slots as $position => $slot) {
            if ($slot['memoryId'] !== null && !($eligible[$slot['memoryId']] ?? false)) {
                $this->connection->executeStatement(
                    'UPDATE daily_quiz_memory SET memory_id = NULL WHERE id = ? AND memory_id = ?',
                    [$slot['id'], $slot['memoryId']],
                );
                $slots[$position]['memoryId'] = null;
            }
        }
    }

    /** @throws UniqueConstraintViolationException si la place ou le souvenir est déjà pris */
    private function fillSlot(int $quizId, int $position, int $memoryId, ?int $slotId): void
    {
        if ($slotId === null) {
            $this->connection->insert('daily_quiz_memory', ['quiz_id' => $quizId, 'position' => $position, 'memory_id' => $memoryId]);

            return;
        }
        // Conditionnel : si une requête concurrente a déjà pourvu la place, on ne l'écrase pas
        $this->connection->executeStatement(
            'UPDATE daily_quiz_memory SET memory_id = ? WHERE id = ? AND memory_id IS NULL',
            [$memoryId, $slotId],
        );
    }
}
