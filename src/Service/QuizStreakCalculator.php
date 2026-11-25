<?php

namespace App\Service;

/**
 * Série de jours consécutifs avec une partie du quiz du jour terminée.
 *
 * Aucun compteur n'est stocké : on part des jours où une partie a été terminée.
 * La série courante reste celle d'hier tant qu'aujourd'hui n'est pas joué (elle ne casse qu'à la fin de la journée).
 */
final class QuizStreakCalculator
{
    /**
     * @param iterable<\DateTimeInterface|string> $finishedDays jours (ou « Y-m-d ») des parties terminées, doublons admis
     */
    public function current(iterable $finishedDays, \DateTimeInterface|string $today): int
    {
        $todayIndex = $this->dayIndex($today);
        $days = array_flip(array_filter(
            $this->dayIndexes($finishedDays),
            fn (int $day) => $day <= $todayIndex,
        ));

        $cursor = isset($days[$todayIndex]) ? $todayIndex : $todayIndex - 1;
        $streak = 0;
        while (isset($days[$cursor])) {
            ++$streak;
            --$cursor;
        }

        return $streak;
    }

    /**
     * @param iterable<\DateTimeInterface|string> $finishedDays
     */
    public function best(iterable $finishedDays): int
    {
        $best = $run = 0;
        $previous = null;
        foreach ($this->dayIndexes($finishedDays) as $day) {
            $run = ($previous !== null && $day === $previous + 1) ? $run + 1 : 1;
            $best = max($best, $run);
            $previous = $day;
        }

        return $best;
    }

    /**
     * @param iterable<\DateTimeInterface|string> $days
     * @return list<int> jours triés, sans doublon
     */
    private function dayIndexes(iterable $days): array
    {
        $indexes = [];
        foreach ($days as $day) {
            $indexes[$this->dayIndex($day)] = true;
        }
        $indexes = array_keys($indexes);
        sort($indexes);

        return $indexes;
    }

    /** Numéro du jour calendaire (jours depuis 1970-01-01), indépendant du fuseau et de l'heure d'été */
    private function dayIndex(\DateTimeInterface|string $day): int
    {
        $ymd = $day instanceof \DateTimeInterface ? $day->format('Y-m-d') : $day;

        return intdiv((new \DateTimeImmutable($ymd . ' 00:00:00', new \DateTimeZone('UTC')))->getTimestamp(), 86400);
    }
}
