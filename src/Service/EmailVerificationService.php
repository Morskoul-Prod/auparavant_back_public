<?php

namespace App\Service;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Confirmation de l'adresse email : envoi d'un lien à usage unique (24 h), puis validation du jeton.
 * Le jeton est aléatoire (256 bits) ; seul son SHA-256 est stocké (un jeton unique par compte : une nouvelle
 * demande invalide la précédente).
 */
class EmailVerificationService
{
    public const LIFETIME_HOURS = 24;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $users,
        private readonly DeferredMailer $mailer,
        private readonly EmailComposer $composer,
        private readonly string $frontUrl,
    ) {
    }

    /** Génère un nouveau jeton et envoie le lien (inscription). Sans effet si l'adresse est déjà confirmée. */
    public function send(User $user): void
    {
        if ($user->isEmailVerified()) {
            return;
        }

        $token = bin2hex(random_bytes(32));
        $user->setEmailVerificationToken(self::hash($token), new \DateTimeImmutable('+' . self::LIFETIME_HOURS . ' hours'));
        $this->entityManager->flush();

        $this->mailer->queue($this->composer->create(
            $user->getEmail(),
            'Confirmez votre adresse email',
            'emails/email_verification.html.twig',
            'emails/email_verification.txt.twig',
            [
                'pseudonym' => $user->getPseudonym(),
                'url' => rtrim($this->frontUrl, '/') . '/verify-email/' . $token,
                'expiresInHours' => self::LIFETIME_HOURS,
            ],
        ));
    }

    /** Confirme l'adresse du compte lié au jeton. Renvoie null si le jeton est inconnu ou expiré. */
    public function verify(string $token): ?User
    {
        if ($token === '' || strlen($token) > 128) {
            return null;
        }

        $user = $this->users->findOneBy(['emailVerificationTokenHash' => self::hash($token)]);
        $expiresAt = $user?->getEmailVerificationExpiresAt();
        if (!$user || !$expiresAt || $expiresAt < new \DateTimeImmutable()) {
            return null;
        }

        $user->setEmailVerifiedAt(new \DateTimeImmutable());
        $user->setEmailVerificationToken(null, null); // usage unique
        $this->entityManager->flush();

        return $user;
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
