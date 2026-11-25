<?php

namespace App\Tests\Service;

use App\Service\QuizStreakCalculator;
use PHPUnit\Framework\TestCase;

class QuizStreakCalculatorTest extends TestCase
{
    private QuizStreakCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new QuizStreakCalculator();
    }

    public function testAucunePartie(): void
    {
        $this->assertSame(0, $this->calculator->current([], '2026-11-03'));
        $this->assertSame(0, $this->calculator->best([]));
    }

    public function testJoursConsecutifsJusqu_AAujourdHui(): void
    {
        $days = ['2026-11-01', '2026-11-02', '2026-11-03'];
        $this->assertSame(3, $this->calculator->current($days, '2026-11-03'));
        $this->assertSame(3, $this->calculator->best($days));
    }

    public function testAujourdHuiNonJoueLaSerieDHierResteValide(): void
    {
        $days = ['2026-11-01', '2026-11-02'];
        $this->assertSame(2, $this->calculator->current($days, '2026-11-03'));
    }

    public function testLaSerieCasseQuandHierEtAujourdHuiManquent(): void
    {
        $days = ['2026-10-31', '2026-11-01'];
        $this->assertSame(0, $this->calculator->current($days, '2026-11-03'));
        $this->assertSame(2, $this->calculator->best($days));
    }

    public function testUnTrouLimiteLaSerieCourante(): void
    {
        $days = ['2026-10-28', '2026-10-29', '2026-10-30', '2026-11-02', '2026-11-03'];
        $this->assertSame(2, $this->calculator->current($days, '2026-11-03'));
        $this->assertSame(3, $this->calculator->best($days));
    }

    public function testDoublonsEtDesordre(): void
    {
        $days = ['2026-11-03', '2026-11-01', '2026-11-02', '2026-11-02'];
        $this->assertSame(3, $this->calculator->current($days, '2026-11-03'));
        $this->assertSame(3, $this->calculator->best($days));
    }

    public function testChangementDeMoisEtDAnnee(): void
    {
        $days = ['2025-12-30', '2025-12-31', '2026-01-01', '2026-01-02'];
        $this->assertSame(4, $this->calculator->current($days, '2026-01-02'));
        $this->assertSame(4, $this->calculator->best($days));
    }

    public function testChangementDHeureNEstPasUnTrou(): void
    {
        // Passage à l'heure d'été en Europe (29 mars 2026) et à l'heure d'hiver (25 octobre 2026)
        $this->assertSame(3, $this->calculator->current(['2026-03-28', '2026-03-29', '2026-03-30'], '2026-03-30'));
        $this->assertSame(3, $this->calculator->current(['2026-10-24', '2026-10-25', '2026-10-26'], '2026-10-26'));
    }

    public function testAccepteDesDates(): void
    {
        $days = [new \DateTimeImmutable('2026-11-02 00:00', new \DateTimeZone('Europe/Paris')), new \DateTimeImmutable('2026-11-03', new \DateTimeZone('Europe/Paris'))];
        $this->assertSame(2, $this->calculator->current($days, new \DateTimeImmutable('2026-11-03 18:30', new \DateTimeZone('Europe/Paris'))));
    }

    public function testUnJourFuturEstIgnore(): void
    {
        $this->assertSame(1, $this->calculator->current(['2026-11-03', '2026-11-05'], '2026-11-03'));
    }
}
