<?php

namespace App\Tests\Service;

use App\Service\QuizClock;
use PHPUnit\Framework\TestCase;

class QuizClockTest extends TestCase
{
    public function testLeJourSeCalculeEnHeureDeParis(): void
    {
        $clock = new QuizClock();

        // 23h30 UTC le 3 novembre = 0h30 à Paris le 4 (heure d'hiver)
        $this->assertSame('2026-11-04', $clock->dayOf(new \DateTimeImmutable('2026-11-03 23:30:00', new \DateTimeZone('UTC')))->format('Y-m-d'));
        // 22h30 UTC le 3 novembre = 23h30 à Paris le 3
        $this->assertSame('2026-11-03', $clock->dayOf(new \DateTimeImmutable('2026-11-03 22:30:00', new \DateTimeZone('UTC')))->format('Y-m-d'));
        // Heure d'été : 22h30 UTC = 0h30 à Paris le lendemain
        $this->assertSame('2026-07-02', $clock->dayOf(new \DateTimeImmutable('2026-07-01 22:30:00', new \DateTimeZone('UTC')))->format('Y-m-d'));
        // Fuseau lointain : 23h à Los Angeles = 8h à Paris le lendemain
        $this->assertSame('2026-11-04', $clock->dayOf(new \DateTimeImmutable('2026-11-03 23:00:00', new \DateTimeZone('America/Los_Angeles')))->format('Y-m-d'));
    }

    public function testLeJourEstMinuitParis(): void
    {
        $day = (new QuizClock())->dayOf(new \DateTimeImmutable('2026-11-03 15:42:10', new \DateTimeZone('Europe/Paris')));

        $this->assertSame('00:00:00', $day->format('H:i:s'));
        $this->assertSame('Europe/Paris', $day->getTimezone()->getName());
    }
}
