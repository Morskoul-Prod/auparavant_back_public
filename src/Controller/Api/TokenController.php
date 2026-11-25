<?php

namespace App\Controller\Api;

use App\Service\AllowedOrigins;
use App\Service\RefreshTokenCookie;
use App\Service\RefreshTokenManager;
use App\Service\SessionUser;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Session du front : renouvellement du JWT à partir du cookie HttpOnly de refresh, et déconnexion.
 * Ces routes sont hors du firewall JWT (cf. security.yaml) : un JWT expiré joint par erreur ne les bloque pas.
 */
class TokenController extends AbstractController
{
    public function __construct(private readonly AllowedOrigins $allowedOrigins)
    {
    }

    /**
     * Échange le refresh token (cookie) contre un nouveau JWT et un nouveau refresh token (rotation)
     */
    #[Route('/api/token/refresh', methods: ['POST'])]
    #[OA\Response(
        response: 200,
        description: 'Même format que /api/login_check ; le nouveau refresh token est posé en cookie HttpOnly (Set-Cookie).',
        content: new OA\JsonContent(example: ['token' => 'eyJ0eXAiOiJKV1Qi…', 'data' => ['id' => 5, 'pseudonym' => 'Jeanne', 'email' => 'jeanne@example.com', 'roles' => ['ROLE_USER']]])
    )]
    #[OA\Response(response: 401, description: 'Cookie absent, inconnu, expiré, révoqué ou rejoué : le cookie est effacé, il faut se reconnecter')]
    #[OA\Response(response: 403, description: 'Origine non autorisée')]
    #[OA\Response(response: 429, description: 'Trop d\'appels (30 par minute et par IP) ; en-tête Retry-After en secondes')]
    #[OA\Tag(name: 'user')]
    public function refresh(Request $request, RefreshTokenManager $refreshTokens, JWTTokenManagerInterface $jwtManager, SessionUser $sessionUser, RateLimiterFactory $tokenRefreshLimiter): Response
    {
        if ($response = $this->rejectForeignOrigin($request)) {
            return $response;
        }

        $limit = $tokenRefreshLimiter->create($request->getClientIp() ?? 'unknown')->consume();
        if (!$limit->isAccepted()) {
            $retryAfter = max($limit->getRetryAfter()->getTimestamp() - time(), 1);

            return new JsonResponse(['error' => 'Trop de tentatives, réessayez plus tard', 'retryAfter' => $retryAfter], Response::HTTP_TOO_MANY_REQUESTS, ['Retry-After' => (string) $retryAfter]);
        }

        $plain = (string) $request->cookies->get(RefreshTokenCookie::NAME, '');
        if ($plain === '') {
            return $this->json(['error' => 'Session expirée'], Response::HTTP_UNAUTHORIZED);
        }

        $response = new JsonResponse();
        $user = $refreshTokens->rotate($plain, $response);
        if (!$user) {
            $response = $this->json(['error' => 'Session expirée'], Response::HTTP_UNAUTHORIZED);
            $refreshTokens->clearCookie($response);

            return $response;
        }

        $response->setData(['token' => $jwtManager->create($user), 'data' => $sessionUser->payload($user)]);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    /**
     * Déconnexion : révoque la session (famille de refresh tokens) et efface le cookie
     */
    #[Route('/api/token/logout', methods: ['POST'])]
    #[OA\Response(response: 204, description: 'Session révoquée et cookie effacé (idempotent : 204 même sans cookie)')]
    #[OA\Response(response: 403, description: 'Origine non autorisée')]
    #[OA\Tag(name: 'user')]
    public function logout(Request $request, RefreshTokenManager $refreshTokens): Response
    {
        if ($response = $this->rejectForeignOrigin($request)) {
            return $response;
        }

        if ($plain = (string) $request->cookies->get(RefreshTokenCookie::NAME, '')) {
            $refreshTokens->revoke($plain);
        }

        $response = new Response(null, Response::HTTP_NO_CONTENT);
        $refreshTokens->clearCookie($response);

        return $response;
    }

    /**
     * Protection CSRF en plus de SameSite=Lax : un navigateur envoie toujours Origin sur un POST,
     * on refuse donc toute origine qui n'est pas celle du front.
     */
    private function rejectForeignOrigin(Request $request): ?Response
    {
        $origin = $request->headers->get('Origin');
        if ($origin !== null && !$this->allowedOrigins->contains($origin)) {
            return $this->json(['error' => 'Origine non autorisée'], Response::HTTP_FORBIDDEN);
        }

        return null;
    }
}
