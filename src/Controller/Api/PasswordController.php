<?php

namespace App\Controller\Api;

use App\Service\PasswordResetService;
use App\Validator\PasswordPolicy;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Mot de passe oublié : routes publiques (l'utilisateur n'est pas connecté).
 */
#[Route('/api/password')]
#[OA\Tag(name: 'password')]
class PasswordController extends AbstractController
{
    private const FORGOT_MESSAGE = 'Si un compte correspond à cette adresse, un email vient de lui être envoyé.';

    #[Route('/forgot', name: 'api_password_forgot', methods: ['POST'])]
    #[OA\Post(summary: 'Demande un lien de réinitialisation du mot de passe (réponse identique que le compte existe ou non)')]
    public function forgot(Request $request, PasswordResetService $resets, RateLimiterFactory $passwordForgotLimiter): JsonResponse
    {
        if ($limited = $this->tooManyRequests($passwordForgotLimiter, $request)) {
            return $limited;
        }

        $data = json_decode($request->getContent(), true);
        $email = is_array($data) && is_string($data['email'] ?? null) ? trim($data['email']) : '';
        if ($email === '' || mb_strlen($email) > 180 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->json(['message' => 'Adresse email invalide.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $resets->sendResetLink($email);

        return $this->json(['message' => self::FORGOT_MESSAGE]);
    }

    #[Route('/check', name: 'api_password_check', methods: ['POST'])]
    #[OA\Post(summary: 'Indique si le jeton de réinitialisation est encore valable, sans le consommer')]
    public function check(Request $request, PasswordResetService $resets, RateLimiterFactory $passwordResetLimiter): JsonResponse
    {
        if ($limited = $this->tooManyRequests($passwordResetLimiter, $request)) {
            return $limited;
        }

        $data = json_decode($request->getContent(), true);
        $token = is_array($data) && is_string($data['token'] ?? null) ? $data['token'] : '';

        if ($token === '' || !$resets->isTokenValid($token)) {
            return $this->json(['message' => 'Ce lien est invalide ou a expiré.', 'code' => 'invalid_token'], Response::HTTP_BAD_REQUEST);
        }

        return $this->json(['valid' => true]);
    }

    #[Route('/reset', name: 'api_password_reset', methods: ['POST'])]
    #[OA\Post(summary: 'Change le mot de passe à partir du jeton reçu par email (usage unique)')]
    public function reset(Request $request, PasswordResetService $resets, ValidatorInterface $validator, RateLimiterFactory $passwordResetLimiter): JsonResponse
    {
        if ($limited = $this->tooManyRequests($passwordResetLimiter, $request)) {
            return $limited;
        }

        $data = json_decode($request->getContent(), true);
        $token = is_array($data) && is_string($data['token'] ?? null) ? $data['token'] : '';
        $password = is_array($data) && is_string($data['password'] ?? null) ? $data['password'] : '';

        $errors = [];
        foreach ($validator->validate($password, new PasswordPolicy()) as $violation) {
            $errors['password'][] = $violation->getMessage();
        }
        if ($errors) {
            return $this->json(['message' => 'Données invalides', 'errors' => $errors], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($token === '' || !$resets->reset($token, $password)) {
            return $this->json(['message' => 'Ce lien est invalide ou a expiré. Refaites une demande.', 'code' => 'invalid_token'], Response::HTTP_BAD_REQUEST);
        }

        return $this->json(['message' => 'Mot de passe modifié. Vous pouvez vous connecter.']);
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
