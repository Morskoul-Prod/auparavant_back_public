<?php

namespace App\Exception;

/**
 * Erreur métier du quiz du jour, portant le code HTTP à renvoyer.
 */
final class QuizException extends \RuntimeException
{
    private function __construct(string $message, private readonly int $statusCode)
    {
        parent::__construct($message);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public static function notEnoughMemories(): self
    {
        return new self('Pas assez de souvenirs pour composer le quiz du jour. Réessayez plus tard.', 503);
    }

    public static function noGame(): self
    {
        return new self('Aucune partie en cours aujourd\'hui : démarrez le quiz du jour.', 404);
    }

    public static function gameFinished(): self
    {
        return new self('La partie du jour est déjà terminée.', 409);
    }

    public static function noCurrentRound(): self
    {
        return new self('Aucune manche en cours.', 409);
    }

    public static function roundAlreadyAnswered(): self
    {
        return new self('Cette manche a déjà reçu une réponse.', 409);
    }
}
