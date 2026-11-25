<?php

namespace App\Service;

use App\Entity\User;
use App\Repository\EmailChangeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use SymfonyCasts\Bundle\ResetPassword\Exception\ResetPasswordExceptionInterface;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelperInterface;

/**
 * Mot de passe oublié pour le front (API JSON) : envoi du lien par email, puis changement du mot de passe.
 * Le jeton est géré par symfonycasts/reset-password-bundle : haché en base, valable 1 h, à usage unique.
 */
class PasswordResetService
{
    public function __construct(
        private readonly ResetPasswordHelperInterface $helper,
        private readonly EntityManagerInterface $entityManager,
        private readonly DeferredMailer $mailer,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly RefreshTokenManager $refreshTokens,
        private readonly EmailChangeRepository $emailChanges,
        private readonly EmailComposer $composer,
        private readonly string $frontUrl,
    ) {
    }

    /**
     * Envoie le lien de réinitialisation si un compte correspond à l'email. Ne renvoie rien et ne lève rien :
     * l'appelant répond pareil que le compte existe ou non (pas d'énumération des comptes).
     */
    public function sendResetLink(string $email): void
    {
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => mb_strtolower(trim($email))]);
        if (!$user instanceof User) {
            return;
        }

        try {
            $token = $this->helper->generateResetToken($user);
        } catch (ResetPasswordExceptionInterface) {
            // Demande trop rapprochée de la précédente : on ne renvoie rien, sans le dire
            return;
        }

        $this->mailer->queue($this->composer->create(
            $user->getEmail(),
            'Réinitialisation de votre mot de passe',
            'emails/password_reset.html.twig',
            'emails/password_reset.txt.twig',
            [
                'pseudonym' => $user->getPseudonym(),
                'url' => rtrim($this->frontUrl, '/') . '/reset-password/' . $token->getToken(),
                'expiresInMinutes' => (int) round(($token->getExpiresAt()->getTimestamp() - time()) / 60),
            ],
        ));
    }

    /** Le jeton est-il encore utilisable ? Ne le consomme pas (la page de réinitialisation l'interroge à l'ouverture). */
    public function isTokenValid(string $token): bool
    {
        try {
            $this->helper->validateTokenAndFetchUser($token);

            return true;
        } catch (ResetPasswordExceptionInterface) {
            return false;
        }
    }

    /**
     * Valide le jeton puis change le mot de passe (le jeton est consommé, les sessions ouvertes sont fermées).
     * Renvoie false si le jeton est invalide ou expiré.
     */
    public function reset(string $token, string $plainPassword): bool
    {
        try {
            /** @var User $user */
            $user = $this->helper->validateTokenAndFetchUser($token);
        } catch (ResetPasswordExceptionInterface) {
            return false;
        }

        $this->helper->removeResetRequest($token);

        $user->setPassword($this->hasher->hashPassword($user, $plainPassword));
        $user->setCredentialsChangedAt(new \DateTimeImmutable());
        $this->entityManager->flush();
        $this->refreshTokens->revokeAll($user);
        // Une demande de changement d'adresse faite avant ce changement de mot de passe est annulée
        $this->emailChanges->removePendingForUser($user);

        return true;
    }
}
