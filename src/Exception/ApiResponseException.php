<?php

namespace App\Exception;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Interrompt un traitement avec la réponse JSON d'erreur à renvoyer telle quelle (convertie par
 * `ApiResponseExceptionListener`). Permet aux services d'une route d'API de refuser une requête sans que le
 * contrôleur ait à tester un retour à chaque étape.
 */
final class ApiResponseException extends \RuntimeException
{
    public function __construct(public readonly JsonResponse $response)
    {
        parent::__construct('Réponse d\'erreur de l\'API');
    }

    /** Erreur simple `{error: message}` avec le code HTTP donné */
    public static function error(string $message, int $status = Response::HTTP_BAD_REQUEST): self
    {
        return new self(new JsonResponse(['error' => $message], $status));
    }
}
