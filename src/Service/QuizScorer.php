<?php

namespace App\Service;

use App\Entity\QuizGame;

/**
 * Calcul du score d'une manche du quiz.
 *
 * - Lieu : MAX_LOCATION_SCORE · exp(−d / LOCATION_DECAY_KM), d = distance orthodromique (Haversine) en km.
 *   Avec 50 km : 100 à 0 km, ≈ 82 à 10 km, ≈ 61 à 25 km, ≈ 37 à 50 km, ≈ 14 à 100 km, ≈ 2 à 200 km, 0 dès ≈ 250 km.
 *   Le bon quartier ou la bonne ville rapporte l'essentiel des points ; viser la bonne région ne rapporte presque rien.
 * - Année : MAX_YEAR_SCORE · exp(−|écart| / YEAR_DECAY_YEARS). Avec 25 ans : 100 pour l'année exacte, ≈ 96 à 1 an, ≈ 82 à 5 ans,
 *   ≈ 67 à 10 ans, ≈ 45 à 20 ans, ≈ 14 à 50 ans, ≈ 2 à 100 ans. Une photo ancienne se date mal : l'écart est pardonné.
 * - Manche = lieu + année (200 au plus), partie = 5 manches (1 000 au plus).
 *
 * Les deux sous-scores sont arrondis à l'entier ; la distance est calculée sans arrondi.
 */
final class QuizScorer
{
    public const MAX_LOCATION_SCORE = 100;
    public const LOCATION_DECAY_KM = 50.0;
    public const MAX_YEAR_SCORE = 100;
    public const YEAR_DECAY_YEARS = 25.0;
    public const MAX_ROUND_SCORE = self::MAX_LOCATION_SCORE + self::MAX_YEAR_SCORE;
    public const MAX_GAME_SCORE = QuizGame::ROUNDS_PER_GAME * self::MAX_ROUND_SCORE;

    private const EARTH_RADIUS_KM = 6371.0088;

    /** Distance orthodromique (Haversine) en kilomètres */
    public function distanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $phi1 = deg2rad($lat1);
        $phi2 = deg2rad($lat2);
        $a = sin(($phi2 - $phi1) / 2) ** 2
            + cos($phi1) * cos($phi2) * sin(deg2rad($lng2 - $lng1) / 2) ** 2;

        return 2 * self::EARTH_RADIUS_KM * asin(min(1.0, sqrt($a)));
    }

    public function locationScore(float $distanceKm): int
    {
        return (int) round(self::MAX_LOCATION_SCORE * exp(-max(0.0, $distanceKm) / self::LOCATION_DECAY_KM));
    }

    public function yearScore(int $yearDiff): int
    {
        return (int) round(self::MAX_YEAR_SCORE * exp(-abs($yearDiff) / self::YEAR_DECAY_YEARS));
    }

    /**
     * @return array{distanceKm: float, yearDiff: int, locationScore: int, yearScore: int, score: int}
     */
    public function scoreRound(
        float $trueLat,
        float $trueLng,
        int $trueYear,
        float $answerLat,
        float $answerLng,
        int $answerYear,
    ): array {
        $distance = $this->distanceKm($trueLat, $trueLng, $answerLat, $answerLng);
        $yearDiff = abs($answerYear - $trueYear);
        $locationScore = $this->locationScore($distance);
        $yearScore = $this->yearScore($yearDiff);

        return [
            'distanceKm' => round($distance, 3),
            'yearDiff' => $yearDiff,
            'locationScore' => $locationScore,
            'yearScore' => $yearScore,
            'score' => $locationScore + $yearScore,
        ];
    }
}
