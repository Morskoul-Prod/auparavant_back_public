<?php

namespace App\Tests\Support;

use App\Entity\Memory;
use App\Entity\QuizGame;
use App\Entity\QuizRound;
use App\Entity\User;
use App\Enum\QuizGameStatus;
use App\Service\QuizClock;

/**
 * Outils communs aux tests du quiz du jour.
 */
abstract class QuizTestCase extends ApiTestCase
{
    protected function today(int $offsetDays = 0): \DateTimeImmutable
    {
        $today = (new QuizClock())->today();

        return $offsetDays === 0 ? $today : $today->modify(sprintf('%+d days', $offsetDays));
    }

    /** @return list<Memory> souvenirs éligibles, chacun dans sa propre Location */
    protected function createEligibleMemories(int $count, ?User $author = null): array
    {
        $author ??= $this->createUser();

        return array_map(fn () => $this->createMemory($author), range(1, $count));
    }

    /** Partie insérée directement en base pour un jour donné (sans manches) */
    protected function createGame(User $user, int $offsetDays, QuizGameStatus $status = QuizGameStatus::FINISHED, int $score = 10000): QuizGame
    {
        $game = (new QuizGame())
            ->setUser($user)->setDate($this->today($offsetDays))->setStatus($status)->setTotalScore($score);
        if ($status === QuizGameStatus::FINISHED) {
            $game->setFinishedAt(new \DateTimeImmutable());
        }
        $this->em->persist($game);
        $this->em->flush();

        return $game;
    }

    protected function daily(User $user): array
    {
        $this->client->request('GET', '/api/secure/quiz/daily', server: $this->authHeaders($user));
        $this->assertResponseIsSuccessful();

        return $this->json();
    }

    protected function startQuiz(User $user): void
    {
        $this->client->request('POST', '/api/secure/quiz/daily/start', server: $this->authHeaders($user));
    }

    protected function answer(User $user, array $payload): void
    {
        $this->client->request(
            'POST',
            '/api/secure/quiz/daily/answer',
            server: $this->authHeaders($user) + ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($payload),
        );
    }

    /** Répond à la manche courante avec la vérité exacte (10 000 points) ou avec un écart donné */
    protected function answerCurrentRound(User $user, int $yearOffset = 0, float $latOffset = 0.0): void
    {
        $round = $this->currentRoundOf($user);
        $this->answer($user, ['lat' => $round->getTrueLat() + $latOffset, 'lng' => $round->getTrueLng(), 'year' => $round->getTrueYear() + $yearOffset]);
    }

    /** @return list<QuizRound> manches de la partie du jour, dans l'ordre */
    protected function roundsOf(User $user): array
    {
        $this->em->clear();
        $game = $this->em->getRepository(QuizGame::class)->findOneBy(['user' => $user->getId(), 'date' => $this->today()]);

        return $game ? array_values($game->getRounds()->toArray()) : [];
    }

    protected function currentRoundOf(User $user): QuizRound
    {
        foreach ($this->roundsOf($user) as $round) {
            if (!$round->isAnswered()) {
                return $round;
            }
        }
        $this->fail('Aucune manche en cours');
    }

    /** Joue les 5 manches avec la vérité exacte */
    protected function playPerfectGame(User $user): void
    {
        $this->startQuiz($user);
        for ($i = 0; $i < QuizGame::ROUNDS_PER_GAME; ++$i) {
            $this->answerCurrentRound($user);
            $this->assertResponseStatusCodeSame(200);
        }
    }

    /** Toutes les clés d'un tableau, à tous les niveaux */
    protected function allKeys(array $data): array
    {
        $keys = [];
        array_walk_recursive($data, function () {});
        foreach ($data as $key => $value) {
            if (is_string($key)) {
                $keys[] = $key;
            }
            if (is_array($value)) {
                $keys = array_merge($keys, $this->allKeys($value));
            }
        }

        return $keys;
    }
}
