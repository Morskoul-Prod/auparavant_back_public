<?php

namespace App\Tests\Service;

use App\Service\QuizScorer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class QuizScorerTest extends TestCase
{
    private QuizScorer $scorer;

    protected function setUp(): void
    {
        $this->scorer = new QuizScorer();
    }

    public function testConstantesDeScore(): void
    {
        $this->assertSame(100, QuizScorer::MAX_LOCATION_SCORE);
        $this->assertSame(100, QuizScorer::MAX_YEAR_SCORE);
        $this->assertSame(200, QuizScorer::MAX_ROUND_SCORE);
        $this->assertSame(1000, QuizScorer::MAX_GAME_SCORE);
        $this->assertSame(50.0, QuizScorer::LOCATION_DECAY_KM);
        $this->assertSame(25.0, QuizScorer::YEAR_DECAY_YEARS);
    }

    #[DataProvider('locationScores')]
    public function testScoreLieu(float $km, int $expected): void
    {
        $this->assertSame($expected, $this->scorer->locationScore($km));
    }

    public static function locationScores(): iterable
    {
        yield 'pile sur le lieu' => [0.0, 100];
        yield '1 km' => [1.0, 98];
        yield '10 km' => [10.0, 82];
        yield '25 km' => [25.0, 61];
        yield 'une échelle (50 km)' => [50.0, 37];
        yield '100 km' => [100.0, 14];
        yield '200 km' => [200.0, 2];
        yield '500 km' => [500.0, 0];
        yield 'bout du monde' => [20015.0, 0];
        yield 'distance négative traitée comme zéro' => [-5.0, 100];
    }

    public function testScoreLieuDecroitAvecLaDistance(): void
    {
        $previous = PHP_INT_MAX;
        foreach ([0, 1, 10, 100, 1000, 5000, 20000] as $km) {
            $score = $this->scorer->locationScore((float) $km);
            $this->assertLessThanOrEqual($previous, $score);
            $previous = $score;
        }
    }

    #[DataProvider('yearScores')]
    public function testScoreAnnee(int $diff, int $expected): void
    {
        $this->assertSame($expected, $this->scorer->yearScore($diff));
    }

    public static function yearScores(): iterable
    {
        yield 'année exacte' => [0, 100];
        yield 'un an' => [1, 96];
        yield '5 ans' => [5, 82];
        yield '10 ans' => [10, 67];
        yield '20 ans' => [20, 45];
        yield '50 ans' => [50, 14];
        yield '100 ans' => [100, 2];
        yield 'très loin' => [400, 0];
        yield 'écart négatif' => [-3, 89];
    }

    public function testDistanceHaversine(): void
    {
        $this->assertSame(0.0, $this->scorer->distanceKm(48.85, 2.35, 48.85, 2.35));
        $this->assertEqualsWithDelta(111.195, $this->scorer->distanceKm(0, 0, 0, 1), 0.01);
        $this->assertEqualsWithDelta(343.5, $this->scorer->distanceKm(48.8566, 2.3522, 51.5074, -0.1278), 1.5); // Paris - Londres
        $this->assertEqualsWithDelta(20015.09, $this->scorer->distanceKm(0, 0, 0, 180), 0.5); // antipodes
        // Symétrique
        $this->assertEqualsWithDelta(
            $this->scorer->distanceKm(43.3, 5.4, 50.6, 3.06),
            $this->scorer->distanceKm(50.6, 3.06, 43.3, 5.4),
            0.0001,
        );
    }

    public function testScoreDeManche(): void
    {
        $perfect = $this->scorer->scoreRound(48.85, 2.35, 1950, 48.85, 2.35, 1950);
        $this->assertSame(['distanceKm' => 0.0, 'yearDiff' => 0, 'locationScore' => 100, 'yearScore' => 100, 'score' => 200], $perfect);

        $worst = $this->scorer->scoreRound(48.85, 2.35, 1950, -48.85, -177.65, 1900);
        $this->assertSame(14, $worst['yearScore']); // 50 ans d'écart
        $this->assertLessThanOrEqual(1, $worst['locationScore']);
        $this->assertSame($worst['locationScore'] + $worst['yearScore'], $worst['score']);

        $mixed = $this->scorer->scoreRound(0, 0, 1950, 0, 1, 1953);
        $this->assertSame(3, $mixed['yearDiff']);
        $this->assertSame(89, $mixed['yearScore']);
        $this->assertSame(11, $mixed['locationScore']);
        $this->assertSame(100, $mixed['score']);
    }
}
