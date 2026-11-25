<?php

namespace App\Service;

use App\Entity\EmailChange;
use App\Entity\User;
use App\Repository\EmailChangeRepository;
use App\Repository\ResetPasswordRequestRepository;
use App\Repository\UserRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Changement d'adresse email d'un compte : la nouvelle adresse reste « en attente » jusqu'à ce que son
 * propriétaire clique le lien reçu ; l'adresse du compte (connexion, réinitialisation du mot de passe) ne change
 * qu'à ce moment-là. Jetons : 256 bits aléatoires, seul le SHA-256 est stocké.
 */
class EmailChangeService
{
    public const CONFIRM_LIFETIME_HOURS = 24;
    public const REVERT_LIFETIME_DAYS = 7;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EmailChangeRepository $changes,
        private readonly UserRepository $users,
        private readonly RefreshTokenManager $refreshTokens,
        private readonly ResetPasswordRequestRepository $resetRequests,
        private readonly PasswordResetService $passwordResets,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly DeferredMailer $mailer,
        private readonly EmailComposer $composer,
        private readonly string $frontUrl,
    ) {
    }

    /**
     * Enregistre la demande (elle remplace celle en attente) et envoie le lien à la NOUVELLE adresse.
     * L'appelant a déjà vérifié que l'adresse est valide, libre et différente de l'adresse courante.
     */
    public function request(User $user, string $newEmail): void
    {
        $this->changes->removePendingForUser($user);

        $token = bin2hex(random_bytes(32));
        $change = new EmailChange(
            $user,
            $user->getEmail(),
            $newEmail,
            self::hash($token),
            new \DateTimeImmutable('+' . self::CONFIRM_LIFETIME_HOURS . ' hours'),
        );
        $this->entityManager->persist($change);
        $this->entityManager->flush();

        $this->queueConfirmationMail($user, $newEmail, $token);
    }

    /**
     * Confirme le changement à partir du lien reçu à la nouvelle adresse : le compte prend la nouvelle adresse
     * (confirmée), toutes ses sessions sont fermées (l'identifiant de connexion change) et l'ANCIENNE adresse reçoit
     * une alerte avec un lien de rétablissement, sauf si elle n'avait jamais été confirmée (voir EmailChange::confirm).
     */
    public function confirm(string $token): EmailChangeOutcome
    {
        $now = new \DateTimeImmutable();
        $change = $this->findByToken($token, fn (string $hash) => $this->changes->findOneByConfirmTokenHash($hash));
        if (!$change || !$change->isConfirmable($now)) {
            return EmailChangeOutcome::Invalid;
        }

        $user = $change->getUser();
        $newEmail = $change->getNewEmail();
        $other = $this->users->findOneBy(['email' => $newEmail]);
        if (($other && $other !== $user) || $this->isReservedForRevert($newEmail, $user)) {
            return EmailChangeOutcome::EmailTaken;
        }

        $revertToken = null;
        try {
            $this->entityManager->wrapInTransaction(function () use ($user, $change, $newEmail, $now, &$revertToken): void {
                // Lu AVANT d'être modifié : seule une ancienne adresse confirmée mérite une alerte et un rétablissement
                $oldEmailWasVerified = $user->isEmailVerified();

                $user->setEmail($newEmail);
                $user->setEmailVerifiedAt($now);
                $user->setEmailVerificationToken(null, null);
                $user->setCredentialsChangedAt($now);
                $user->setUpdatedAt($now);

                if ($oldEmailWasVerified) {
                    $revertToken = bin2hex(random_bytes(32));
                    $change->confirm($now, self::hash($revertToken), $now->modify('+' . self::REVERT_LIFETIME_DAYS . ' days'));
                } else {
                    $change->confirm($now, null, null);
                }
                $this->entityManager->flush();
                $this->refreshTokens->revokeAll($user);
            });
        } catch (UniqueConstraintViolationException) {
            // Quelqu'un a pris l'adresse entre la vérification et l'écriture
            return EmailChangeOutcome::EmailTaken;
        }

        if ($revertToken !== null) {
            $this->mailer->queue($this->composer->create(
                $change->getOldEmail(),
                'Votre adresse email a été modifiée',
                'emails/email_change_notice.html.twig',
                'emails/email_change_notice.txt.twig',
                [
                    'pseudonym' => $user->getPseudonym(),
                    'oldEmail' => $change->getOldEmail(),
                    'newEmailMasked' => EmailMasker::mask($newEmail),
                    'changedAt' => $now->format('d/m/Y à H:i'),
                    'url' => rtrim($this->frontUrl, '/') . '/revert-email/' . $revertToken,
                    'expiresInDays' => self::REVERT_LIFETIME_DAYS,
                ],
            ));
        }

        return EmailChangeOutcome::Done;
    }

    /**
     * Rétablit l'ancienne adresse à partir du lien reçu à l'ANCIENNE adresse (« Ce n'était pas moi »). Le compte est
     * repris en main : mot de passe invalidé (le pirate peut le connaître), sessions fermées, demandes de
     * réinitialisation et changements en attente supprimés, liens de rétablissement de ce changement et des
     * suivants invalidés (ceux des changements antérieurs restent valables) ; un lien de réinitialisation du mot de
     * passe part vers l'ancienne adresse. Le tout dans une transaction : rien n'est fait à moitié.
     */
    public function revert(string $token): EmailChangeOutcome
    {
        $now = new \DateTimeImmutable();
        $change = $this->findByToken($token, fn (string $hash) => $this->changes->findOneByRevertTokenHash($hash));
        if (!$change || !$change->isRevertable($now)) {
            return EmailChangeOutcome::Invalid;
        }

        $user = $change->getUser();
        $oldEmail = $change->getOldEmail();
        $other = $this->users->findOneBy(['email' => $oldEmail]);
        if ($other && $other !== $user) {
            return EmailChangeOutcome::EmailTaken;
        }

        try {
            $this->entityManager->wrapInTransaction(function () use ($user, $change, $oldEmail, $now): void {
                $user->setEmail($oldEmail);
                // Le clic depuis cette boîte prouve qu'on la contrôle
                $user->setEmailVerifiedAt($now);
                $user->setPassword($this->hasher->hashPassword($user, bin2hex(random_bytes(32))));
                $user->setCredentialsChangedAt($now);
                $user->setUpdatedAt($now);
                $change->markReverted($now);
                $this->changes->removePendingForUser($user);
                $this->changes->clearRevertTokensFrom($user, (int) $change->getId());
                $this->resetRequests->removeAllForUser($user);
                $this->entityManager->flush();
                $this->refreshTokens->revokeAll($user);
            });
        } catch (UniqueConstraintViolationException) {
            return EmailChangeOutcome::EmailTaken;
        }

        $this->passwordResets->sendResetLink($oldEmail);

        return EmailChangeOutcome::Done;
    }

    /**
     * Cette adresse est-elle réservée par un lien de rétablissement encore valable ? Tant qu'il l'est, personne
     * d'autre que le titulaire du changement (`$except`) ne peut la prendre (inscription, changement d'adresse) :
     * sinon un pirate bloquerait le rétablissement en s'inscrivant avec l'adresse libérée.
     */
    public function isReservedForRevert(string $email, ?User $except = null): bool
    {
        $reservation = $this->changes->findRevertReservation($email, new \DateTimeImmutable());

        return $reservation !== null && ($except === null || $reservation->getUser()->getId() !== $except->getId());
    }

    /**
     * Renvoie le lien de la demande en attente (nouveau jeton, l'ancien cesse de fonctionner) à la nouvelle adresse.
     * Renvoie false s'il n'y a pas de demande en attente.
     */
    public function resendPending(User $user): bool
    {
        $change = $this->changes->findPendingForUser($user);
        if (!$change) {
            return false;
        }

        $token = bin2hex(random_bytes(32));
        $change->regenerateConfirmation(self::hash($token), new \DateTimeImmutable('+' . self::CONFIRM_LIFETIME_HOURS . ' hours'));
        $this->entityManager->flush();

        $this->queueConfirmationMail($user, $change->getNewEmail(), $token);

        return true;
    }

    /** Nouvelle adresse en attente de confirmation (même si son lien a expiré), ou null. */
    public function pendingEmail(User $user): ?string
    {
        return $this->changes->findPendingForUser($user)?->getNewEmail();
    }

    private function queueConfirmationMail(User $user, string $newEmail, string $token): void
    {
        $this->mailer->queue($this->composer->create(
            $newEmail,
            'Confirmez votre nouvelle adresse email',
            'emails/email_change_confirm.html.twig',
            'emails/email_change_confirm.txt.twig',
            [
                'pseudonym' => $user->getPseudonym(),
                'url' => rtrim($this->frontUrl, '/') . '/confirm-new-email/' . $token,
                'expiresInHours' => self::CONFIRM_LIFETIME_HOURS,
            ],
        ));
    }

    /** @param callable(string): ?EmailChange $finder */
    private function findByToken(string $token, callable $finder): ?EmailChange
    {
        if ($token === '' || strlen($token) > 128) {
            return null;
        }

        return $finder(self::hash($token));
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
