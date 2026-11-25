<?php

namespace App\Entity;

use App\Repository\RefreshTokenRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Refresh token de la session du front. Seul le hash SHA-256 est stocké : la valeur en clair n'existe que
 * dans le cookie HttpOnly du navigateur. Chaque utilisation (rotation) marque le jeton comme utilisé et en émet
 * un nouveau dans la même famille ; rejouer un jeton déjà utilisé révoque toute la famille.
 * Supprimé avec le compte (CASCADE).
 */
#[ORM\Entity(repositoryClass: RefreshTokenRepository::class)]
#[ORM\Index(name: 'idx_refresh_token_family', columns: ['family_id'])]
#[ORM\Index(name: 'idx_refresh_token_expires_at', columns: ['expires_at'])]
class RefreshToken
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 64, unique: true)]
    private string $tokenHash;

    /** Identifiant partagé par tous les jetons issus d'une même connexion */
    #[ORM\Column(length: 32)]
    private string $familyId;

    /** credentialsChangedAt de l'utilisateur à l'émission (timestamp, 0 si jamais changé) : un changement d'email ou de mot de passe invalide le jeton */
    #[ORM\Column(options: ['default' => 0])]
    private int $credentialsChangedAt = 0;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $usedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    public function __construct(User $user, string $tokenHash, string $familyId, \DateTimeImmutable $expiresAt)
    {
        $this->user = $user;
        $this->tokenHash = $tokenHash;
        $this->familyId = $familyId;
        $this->expiresAt = $expiresAt;
        $this->createdAt = new \DateTimeImmutable();
        $this->credentialsChangedAt = $user->getCredentialsChangedAt()?->getTimestamp() ?? 0;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getFamilyId(): string
    {
        return $this->familyId;
    }

    public function getCredentialsChangedAt(): int
    {
        return $this->credentialsChangedAt;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getUsedAt(): ?\DateTimeImmutable
    {
        return $this->usedAt;
    }

    public function getRevokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function isExpired(\DateTimeImmutable $now): bool
    {
        return $this->expiresAt <= $now;
    }
}
