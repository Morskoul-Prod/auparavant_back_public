<?php

namespace App\Controller\Api;

use App\Entity\User;
use App\Service\EmailChangeService;
use App\Service\EmailVerificationService;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Confirmation de l'adresse email : validation du lien (publique : il peut s'ouvrir dans un autre navigateur)
 * et renvoi du lien (utilisateur connecté).
 */
#[OA\Tag(name: 'email')]
class EmailVerificationController extends AbstractController
{
    #[Route('/api/email/verify', name: 'api_email_verify', methods: ['POST'])]
    #[OA\Post(summary: 'Confirme l\'adresse email à partir du jeton reçu par email (usage unique, 24 h)')]
    public function verify(Request $request, EmailVerificationService $verification, RateLimiterFactory $emailVerifyLimiter): JsonResponse
    {
        $limit = $emailVerifyLimiter->create($request->getClientIp() ?? 'unknown')->consume();
        if (!$limit->isAccepted()) {
            return $this->tooManyRequests($limit->getRetryAfter()->getTimestamp() - time());
        }

        $data = json_decode($request->getContent(), true);
        $token = is_array($data) && is_string($data['token'] ?? null) ? $data['token'] : '';

        if (!$verification->verify($token)) {
            return $this->json(['message' => 'Ce lien est invalide ou a expiré.', 'code' => 'invalid_token'], Response::HTTP_BAD_REQUEST);
        }

        return $this->json(['message' => 'Adresse email confirmée.']);
    }

    #[Route('/api/secure/email/resend', name: 'api_email_resend', methods: ['POST'])]
    #[OA\Post(summary: 'Renvoie le lien de confirmation : à la nouvelle adresse si un changement est en attente, sinon à l\'adresse du compte non confirmée')]
    #[OA\Response(response: 409, description: 'Adresse déjà confirmée')]
    #[OA\Response(response: 429, description: 'Trop de demandes (3 par 15 minutes) ; en-tête Retry-After')]
    public function resend(Request $request, EmailVerificationService $verification, EmailChangeService $emailChange, RateLimiterFactory $emailResendLimiter): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        // Une demande de changement d'adresse en attente est prioritaire : le lien repart vers la nouvelle adresse
        if ($user->isEmailVerified() && $emailChange->pendingEmail($user) === null) {
            return $this->json(['message' => 'Votre adresse est déjà confirmée.', 'code' => 'already_verified'], Response::HTTP_CONFLICT);
        }

        $limit = $emailResendLimiter->create((string) $user->getId())->consume();
        if (!$limit->isAccepted()) {
            return $this->tooManyRequests($limit->getRetryAfter()->getTimestamp() - time());
        }

        if (!$emailChange->resendPending($user)) {
            $verification->send($user);
        }

        return $this->json(['message' => 'Un email de confirmation vient de vous être envoyé.']);
    }

    private function tooManyRequests(int $seconds): JsonResponse
    {
        return $this->json(
            ['message' => 'Trop de tentatives, réessayez plus tard.'],
            Response::HTTP_TOO_MANY_REQUESTS,
            ['Retry-After' => (string) max($seconds, 1)],
        );
    }
}
