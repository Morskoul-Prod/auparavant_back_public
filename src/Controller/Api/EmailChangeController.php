<?php

namespace App\Controller\Api;

use App\Service\EmailChangeOutcome;
use App\Service\EmailChangeService;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Confirmation d'un changement d'adresse email et rétablissement de l'ancienne adresse. Routes publiques : les
 * liens s'ouvrent depuis une boîte mail, pas forcément dans le navigateur où l'utilisateur est connecté.
 */
#[OA\Tag(name: 'email')]
class EmailChangeController extends AbstractController
{
    #[Route('/api/email/change/confirm', name: 'api_email_change_confirm', methods: ['POST'])]
    #[OA\Post(summary: 'Confirme la nouvelle adresse email à partir du jeton reçu à cette adresse (usage unique, 24 h)')]
    #[OA\Response(response: 200, description: 'Adresse changée ; toutes les sessions sont fermées, il faut se reconnecter avec la nouvelle adresse')]
    #[OA\Response(response: 400, description: 'Lien invalide ou expiré (`code: invalid_token`)')]
    #[OA\Response(response: 409, description: 'La nouvelle adresse appartient désormais à un autre compte (`code: email_taken`)')]
    public function confirm(Request $request, EmailChangeService $emailChange, RateLimiterFactory $emailVerifyLimiter): JsonResponse
    {
        if ($limited = $this->tooManyRequests($emailVerifyLimiter, $request)) {
            return $limited;
        }

        return $this->respond(
            $emailChange->confirm($this->tokenFrom($request)),
            'Nouvelle adresse confirmée. Reconnectez-vous avec cette adresse.',
        );
    }

    #[Route('/api/email/change/revert', name: 'api_email_change_revert', methods: ['POST'])]
    #[OA\Post(summary: 'Rétablit l\'ancienne adresse email à partir du jeton de l\'alerte (usage unique, 7 jours)')]
    #[OA\Response(response: 200, description: 'Ancienne adresse rétablie, sessions fermées, mot de passe invalidé ; un lien de réinitialisation est envoyé à cette adresse')]
    #[OA\Response(response: 400, description: 'Lien invalide, expiré ou déjà utilisé (`code: invalid_token`)')]
    #[OA\Response(response: 409, description: 'L\'ancienne adresse appartient désormais à un autre compte (`code: email_taken`)')]
    public function revert(Request $request, EmailChangeService $emailChange, RateLimiterFactory $emailVerifyLimiter): JsonResponse
    {
        if ($limited = $this->tooManyRequests($emailVerifyLimiter, $request)) {
            return $limited;
        }

        return $this->respond(
            $emailChange->revert($this->tokenFrom($request)),
            'Ancienne adresse rétablie. Un email pour choisir un nouveau mot de passe vient de vous être envoyé.',
        );
    }

    private function tokenFrom(Request $request): string
    {
        $data = json_decode($request->getContent(), true);

        return is_array($data) && is_string($data['token'] ?? null) ? $data['token'] : '';
    }

    private function respond(EmailChangeOutcome $outcome, string $successMessage): JsonResponse
    {
        return match ($outcome) {
            EmailChangeOutcome::Done => $this->json(['message' => $successMessage]),
            EmailChangeOutcome::Invalid => $this->json(['message' => 'Ce lien est invalide ou a expiré.', 'code' => 'invalid_token'], Response::HTTP_BAD_REQUEST),
            EmailChangeOutcome::EmailTaken => $this->json(['message' => 'Cette adresse est désormais utilisée par un autre compte.', 'code' => 'email_taken'], Response::HTTP_CONFLICT),
        };
    }

    private function tooManyRequests(RateLimiterFactory $factory, Request $request): ?JsonResponse
    {
        $limit = $factory->create($request->getClientIp() ?? 'unknown')->consume();
        if ($limit->isAccepted()) {
            return null;
        }
        $retryAfter = max($limit->getRetryAfter()->getTimestamp() - time(), 1);

        return $this->json(['message' => 'Trop de tentatives, réessayez plus tard.'], Response::HTTP_TOO_MANY_REQUESTS, ['Retry-After' => (string) $retryAfter]);
    }
}
