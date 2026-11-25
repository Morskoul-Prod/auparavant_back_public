<?php

namespace App\Service;

use App\Entity\User;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * Garde des routes sensibles du compte (email, mot de passe, suppression) : limitation des tentatives
 * par utilisateur et par IP, vérification du mot de passe actuel, normalisation des erreurs.
 * Le mot de passe n'est jamais journalisé ni renvoyé.
 */
class AccountGuard
{
    public function __construct(
        private readonly RateLimiterFactory $accountSecurityUserLimiter,
        private readonly RateLimiterFactory $accountSecurityIpLimiter,
        private readonly UserPasswordHasherInterface $hasher,
    ) {
    }

    /**
     * Consomme un essai sur les deux compteurs ; renvoie un 429 avec Retry-After (secondes) s'ils sont épuisés.
     */
    public function rateLimit(Request $request, User $user): ?JsonResponse
    {
        $retryAfter = null;
        foreach ([
            $this->accountSecurityUserLimiter->create((string) $user->getId()),
            $this->accountSecurityIpLimiter->create($request->getClientIp() ?? 'unknown'),
        ] as $limiter) {
            $limit = $limiter->consume();
            if (!$limit->isAccepted()) {
                $retryAfter = max($retryAfter ?? 1, $limit->getRetryAfter()->getTimestamp() - time(), 1);
            }
        }

        if ($retryAfter === null) {
            return null;
        }

        return new JsonResponse(
            ['error' => 'Trop de tentatives, réessayez plus tard', 'retryAfter' => $retryAfter],
            Response::HTTP_TOO_MANY_REQUESTS,
            ['Retry-After' => (string) $retryAfter]
        );
    }

    /**
     * Message d'erreur du champ currentPassword, ou null s'il est valide.
     */
    public function currentPasswordError(User $user, mixed $currentPassword): ?string
    {
        if (!is_string($currentPassword) || $currentPassword === '') {
            return 'Veuillez saisir votre mot de passe actuel.';
        }

        return $this->hasher->isPasswordValid($user, $currentPassword) ? null : 'Mot de passe actuel incorrect.';
    }
}
