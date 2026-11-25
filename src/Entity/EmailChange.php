<?php

namespace App\Entity;

use App\Repository\EmailChangeRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Demande de changement d'adresse email d'un compte.
 *
 * Cycle de vie : en attente (jeton de confirmation envoyé à la NOUVELLE adresse, l'adresse du compte ne change pas)
 * → confirmée (l'adresse du compte change ; un jeton de rétablissement est envoyé à l'ANCIENNE adresse)
 * → éventuellement rétablie. Seuls les SHA-256 des jetons sont stockés. Supprimée avec le compte (CASCADE).
 */
#[ORM\Entity(repositoryClass: EmailChangeRepository::class)]
#[ORM\Index(name: 'idx_email_change_confirm_token', columns: ['confirm_token_hash'])]
#[ORM\Index(name: 'idx_email_change_revert_token', columns: ['revert_token_hash'])]
class EmailChange
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 180)]
    private string $oldEmail;

    #[ORM\Column(length: 180)]
    private string $newEmail;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $confirmTokenHash;

    #[ORM\Column]
    private \DateTimeImmutable $confirmExpiresAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $confirmedAt = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $revertTokenHash = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $revertExpiresAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $revertedAt = null;

    public function __construct(User $user, string $oldEmail, string $newEmail, string $confirmTokenHash, \DateTimeImmutable $confirmExpiresAt)
    {
        $this->user = $user;
        $this->oldEmail = $oldEmail;
        $this->newEmail = $newEmail;
        $this->confirmTokenHash = $confirmTokenHash;
        $this->confirmExpiresAt = $confirmExpiresAt;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getOldEmail(): string
    {
        return $this->oldEmail;
    }

    public function getNewEmail(): string
    {
        return $this->newEmail;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getConfirmExpiresAt(): \DateTimeImmutable
    {
        return $this->confirmExpiresAt;
    }

    public function getConfirmedAt(): ?\DateTimeImmutable
    {
        return $this->confirmedAt;
    }

    public function getRevertExpiresAt(): ?\DateTimeImmutable
    {
        return $this->revertExpiresAt;
    }

    public function getRevertedAt(): ?\DateTimeImmutable
    {
        return $this->revertedAt;
    }

    /** Non confirmée, jeton présent et non expiré. */
    public function isConfirmable(\DateTimeImmutable $now): bool
    {
        return $this->confirmedAt === null && $this->confirmTokenHash !== null && $this->confirmExpiresAt > $now;
    }

    /** Nouveau jeton de confirmation (renvoi du mail) : l'ancien cesse de fonctionner. */
    public function regenerateConfirmation(string $hash, \DateTimeImmutable $expiresAt): void
    {
        $this->confirmTokenHash = $hash;
        $this->confirmExpiresAt = $expiresAt;
    }

    /**
     * Confirme le changement : le jeton de confirmation est consommé, un jeton de rétablissement est posé.
     * Sans jeton de rétablissement (null) si l'ancienne adresse n'avait jamais été confirmée : personne ne peut
     * prouver qu'elle est à lui, donc aucun lien de prise de contrôle ne doit partir vers elle.
     */
    public function confirm(\DateTimeImmutable $now, ?string $revertTokenHash, ?\DateTimeImmutable $revertExpiresAt): void
    {
        $this->confirmedAt = $now;
        $this->confirmTokenHash = null;
        $this->revertTokenHash = $revertTokenHash;
        $this->revertExpiresAt = $revertExpiresAt;
    }

    /** Confirmée, non rétablie, jeton de rétablissement présent et non expiré. */
    public function isRevertable(\DateTimeImmutable $now): bool
    {
        return $this->confirmedAt !== null
            && $this->revertedAt === null
            && $this->revertTokenHash !== null
            && $this->revertExpiresAt !== null
            && $this->revertExpiresAt > $now;
    }

    public function markReverted(\DateTimeImmutable $now): void
    {
        $this->revertedAt = $now;
        $this->revertTokenHash = null;
    }
}
