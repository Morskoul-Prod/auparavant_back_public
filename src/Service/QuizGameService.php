<?php

namespace App\Service;

use App\Entity\Memory;
use App\Entity\QuizGame;
use App\Entity\QuizRound;
use App\Entity\User;
use App\Enum\QuizGameStatus;
use App\Exception\QuizException;
use App\Repository\QuizGameRepository;
use App\Repository\QuizRoundRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Déroulé d'une partie du quiz du jour : démarrage (idempotent), réponse à la manche courante.
 *
 * Seule la partie du jour existe pour le joueur : une partie commencée un jour précédent et non terminée est
 * abandonnée, elle n'est jamais reprise.
 */
class QuizGameService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly QuizGameRepository $games,
        private readonly QuizRoundRepository $rounds,
        private readonly DailyQuizProvider $dailyQuiz,
        private readonly QuizMemorySelector $selector,
        private readonly QuizScorer $scorer,
        private readonly QuizClock $clock,
    ) {
    }

    public function findToday(User $user): ?QuizGame
    {
        return $this->games->findOneWithRounds($user, $this->clock->today());
    }

    /**
     * Crée la partie du jour ou renvoie celle qui existe déjà.
     *
     * @return array{0: QuizGame, 1: bool} la partie et « vrai si elle vient d'être créée »
     * @throws QuizException 503 s'il n'y a pas assez de souvenirs éligibles
     */
    public function start(User $user): array
    {
        $today = $this->clock->today();
        if ($game = $this->games->findOneWithRounds($user, $today)) {
            return [$game, false];
        }

        $memories = $this->dailyQuiz->memoriesFor($today);

        try {
            // La ligne de la partie est réservée en premier (SQL direct) : sur un double clic, la seconde requête
            // attend la première, puis reçoit la violation d'unicité (user, date) et relit la partie créée.
            $game = $this->em->getConnection()->transactional(function () use ($user, $today, $memories) {
                $this->em->getConnection()->insert('quiz_game', [
                    'user_id' => $user->getId(),
                    'date' => $today->format('Y-m-d'),
                    'status' => QuizGameStatus::IN_PROGRESS->value,
                    'total_score' => 0,
                    'started_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                ]);
                $game = $this->em->find(QuizGame::class, (int) $this->em->getConnection()->lastInsertId());

                foreach ($memories as $index => $memory) {
                    $game->addRound($this->snapshot($memory, $index + 1));
                }
                $this->em->flush();

                return $game;
            });
        } catch (UniqueConstraintViolationException) {
            return [$this->games->findOneWithRounds($user, $today), false];
        }

        return [$game, true];
    }

    /**
     * Répond à la manche courante de la partie du jour.
     *
     * @return array{round: QuizRound, game: QuizGame} la manche répondue (avec sa correction) et la partie à jour
     * @throws QuizException 404 sans partie du jour, 409 si terminée ou si la manche a déjà reçu une réponse
     */
    public function answer(User $user, float $lat, float $lng, int $year): array
    {
        $game = $this->findToday($user) ?? throw QuizException::noGame();
        if ($game->isFinished()) {
            throw QuizException::gameFinished();
        }
        $round = $game->getCurrentRound() ?? throw QuizException::noCurrentRound();

        $scored = $this->scorer->scoreRound($round->getTrueLat(), $round->getTrueLng(), $round->getTrueYear(), $lat, $lng, $year);
        $isLast = $round->getPosition() >= QuizGame::ROUNDS_PER_GAME;
        $now = new \DateTimeImmutable();

        $this->em->getConnection()->transactional(function () use ($round, $game, $lat, $lng, $year, $scored, $isLast, $now) {
            // Une seule requête peut gagner cette mise à jour : les autres (double clic) reçoivent 409
            if (!$this->rounds->recordAnswer($round, $lat, $lng, $year, $scored, $now)) {
                throw QuizException::roundAlreadyAnswered();
            }

            $update = $this->em->createQueryBuilder()
                ->update(QuizGame::class, 'g')
                ->set('g.totalScore', 'g.totalScore + :score')
                ->where('g.id = :id')
                ->setParameter('score', $scored['score'])
                ->setParameter('id', $game->getId());
            if ($isLast) {
                $update->set('g.status', ':finished')->set('g.finishedAt', ':at')
                    ->setParameter('finished', QuizGameStatus::FINISHED)
                    ->setParameter('at', $now);
            }
            $update->getQuery()->execute();
        });

        // Les mises à jour DQL contournent l'unité de travail : on relit la manche et la partie
        $this->em->refresh($round);
        $this->em->refresh($game);

        return ['round' => $round, 'game' => $game];
    }

    /** Instantané de la vérité : la partie ne dépend plus du souvenir une fois démarrée */
    private function snapshot(Memory $memory, int $position): QuizRound
    {
        $picture = $this->selector->mainPicture($memory) ?? throw QuizException::notEnoughMemories();
        $location = $memory->getLocation();

        return (new QuizRound())
            ->setPosition($position)
            ->setMemory($memory)
            ->setMemoryTitle((string) $memory->getTitle())
            ->setPictureName((string) $picture->getImageName())
            ->setTrueLat((float) $location->getLatitude())
            ->setTrueLng((float) $location->getLongitude())
            ->setTrueYear((int) $memory->getEffectiveYear());
    }
}
