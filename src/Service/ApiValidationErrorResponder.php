<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Valide des entités et convertit les violations en réponse 422 pour l'API.
 *
 * Les clés d'erreur sont préfixées par l'objet (memory.title, place.name...)
 * et les valeurs sont des tableaux de messages.
 */
class ApiValidationErrorResponder
{
    public function __construct(private ValidatorInterface $validator)
    {
    }

    /**
     * Valide chaque objet et renvoie une réponse 422 s'il y a des violations, null sinon.
     *
     * @param array<string, object> $objects objets à valider, indexés par préfixe (ex. ['memory' => $memory])
     */
    public function validate(array $objects): ?JsonResponse
    {
        $errors = [];

        foreach ($objects as $prefix => $object) {
            $errors = array_merge_recursive($errors, $this->formatViolations($prefix, $this->validator->validate($object)));
        }

        return $errors ? $this->createResponse($errors) : null;
    }

    /**
     * @return array<string, list<string>>
     */
    public function formatViolations(string $prefix, ConstraintViolationListInterface $violations): array
    {
        $errors = [];

        foreach ($violations as $violation) {
            $errors[$prefix . '.' . $violation->getPropertyPath()][] = $violation->getMessage();
        }

        return $errors;
    }

    /**
     * @param array<string, list<string>> $errors
     */
    public function createResponse(array $errors): JsonResponse
    {
        return new JsonResponse([
            'error' => 'Données invalides',
            'errors' => $errors,
        ], Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
