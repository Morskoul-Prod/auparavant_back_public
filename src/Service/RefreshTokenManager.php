<?php

namespace App\Service;

use App\Entity\RefreshToken;
use App\Entity\User;
use App\Repository\RefreshTokenRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cycle de vie des refresh tokens : émission, rotation à usage unique, révocation.
 *
 * Un jeton rejoué après sa rotation est soit une course entre deux requêtes légitimes (dans les
 * REPLAY_GRACE_SECONDS : simple refus), soit un vol (au-delà : toute la famille est révoquée et
 * l'utilisateur doit se reconnecter).
 */
class RefreshTokenManager
{
    public const REPLAY_GRACE_SECONDS = 10;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RefreshTokenRepository $tokens,
        private readonly RefreshTokenCookie $cookie,
        private readonly int $ttlSeconds,
    ) {
    }

    /**
     * Émet un refresh token (nouvelle connexion ou, avec $familyId, rotation) et le pose en cookie sur la réponse.
     */
    public function attach(Response $response, User $user, ?string $familyId = null): void
    {
        [$plain, $token] = $this->issue($user, $familyId);
        $response->headers->setCookie($this->cookie->create($plain, $token->getExpiresAt()));
    }

    /**
     * Consomme un refresh token valide et en émet un nouveau (même famille) sur la réponse.
     * Renvoie l'utilisateur, ou null si le jeton est inconnu, expiré, révoqué, rejoué ou périmé.
     */
    public function rotate(string $plain, Response $response): ?User
    {
        $token = $this->tokens->findOneByHash(self::hash($plain));
        if (!$token) {
            return null;
        }

        $now = new \DateTimeImmutable();
        if ($token->getRevokedAt() || $token->isExpired($now)) {
            return null;
        }

        if ($usedAt = $token->getUsedAt()) {
            if ($now->getTimestamp() - $usedAt->getTimestamp() > self::REPLAY_GRACE_SECONDS) {
                $this->tokens->revokeFamily($token->getFamilyId(), $now);
            }

            return null;
        }

        $user = $token->getUser();
        // Email ou mot de passe changé depuis l'émission : la session n'est plus valable
        if ($token->getCredentialsChangedAt() !== ($user->getCredentialsChangedAt()?->getTimestamp() ?? 0)) {
            $this->tokens->revokeFamily($token->getFamilyId(), $now);

            return null;
        }

        // Deux requêtes simultanées avec le même jeton : une seule passe ici
        if ($this->tokens->markUsed($token, $now) !== 1) {
            return null;
        }

        $this->attach($response, $user, $token->getFamilyId());

        return $user;
    }

    /** Révoque la famille du jeton présenté (déconnexion) ; sans effet si le jeton est inconnu. */
    public function revoke(string $plain): void
    {
        if ($token = $this->tokens->findOneByHash(self::hash($plain))) {
            $this->tokens->revokeFamily($token->getFamilyId(), new \DateTimeImmutable());
        }
    }

    /** Révoque toutes les sessions de l'utilisateur (changement d'email ou de mot de passe). */
    public function revokeAll(User $user): void
    {
        $this->tokens->revokeAllForUser($user, new \DateTimeImmutable());
    }

    public function clearCookie(Response $response): void
    {
        $response->headers->setCookie($this->cookie->clear());
    }

    /** @return array{0: string, 1: RefreshToken} valeur en clair (à ne jamais stocker) et entité */
    private function issue(User $user, ?string $familyId): array
    {
        $plain = bin2hex(random_bytes(32));
        $token = new RefreshToken(
            $user,
            self::hash($plain),
            $familyId ?? bin2hex(random_bytes(16)),
            new \DateTimeImmutable(sprintf('+%d seconds', $this->ttlSeconds)),
        );
        $this->em->persist($token);
        $this->em->flush();

        return [$plain, $token];
    }

    private static function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }
}
