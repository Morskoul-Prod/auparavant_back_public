<?php

namespace App\Service;

/**
 * Le jour du quiz se calcule en Europe/Paris, quel que soit le fuseau du serveur.
 */
class QuizClock
{
    public const TIMEZONE = 'Europe/Paris';

    /** Jour (minuit, heure de Paris) de l'instant donné */
    public function dayOf(\DateTimeInterface $instant): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($instant)
            ->setTimezone(new \DateTimeZone(self::TIMEZONE))
            ->setTime(0, 0);
    }

    public function today(): \DateTimeImmutable
    {
        return $this->dayOf(new \DateTimeImmutable());
    }
}
