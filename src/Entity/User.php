<?php

namespace App\Entity;

use App\Repository\UserRepository;
use App\Validator\ReservedPseudonym;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Index(columns: ['email_verification_token_hash'], name: 'idx_user_email_verification_token')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['get_user'])]
    private ?int $id = null;

    /**
     * Seul nom public de l'utilisateur. Modifiable et NON unique (deux comptes peuvent porter le même).
     */
    #[ORM\Column(length: 30)]
    #[Assert\NotBlank(message: 'Le pseudonyme est obligatoire.')]
    #[Assert\Length(
        min: 2,
        max: 30,
        minMessage: 'Votre pseudonyme doit comporter au moins {{ limit }} caractères.',
        maxMessage: 'Votre pseudonyme ne peut pas dépasser {{ limit }} caractères.',
    )]
    #[ReservedPseudonym]
    #[Groups(['get_user_private'])]
    private ?string $pseudonym = null;

    #[ORM\Column(length: 180, unique: true)]
    #[Assert\NotBlank(message: 'L\'adresse électronique est obligatoire.')]
    #[Assert\Email(
        message: 'L\'adresse électronique {{ value }} n\'est pas valide.',
    )]
    #[Groups(['get_user_private'])]
    private ?string $email = null;

    /**
     * @var string Le mot de passe haché
     */
    #[ORM\Column]
    private ?string $password = null;

    #[ORM\Column]
    #[Groups(['get_user_private'])]
    private array $roles = [];

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    /**
     * Moment où l'utilisateur a accepté les CGU et la politique de confidentialité à l'inscription.
     * Null pour les comptes créés avant l'ajout de la case (ou depuis le back-office).
     */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $termsAcceptedAt = null;

    /**
     * Dernier changement d'email ou de mot de passe. Recopié dans le JWT (claim « cca ») : un jeton émis avant
     * ce changement est refusé (voir EventListener/JwtCredentialsListener).
     */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $credentialsChangedAt = null;

    /**
     * Moment où l'utilisateur a confirmé son adresse (lien reçu par email). Null tant qu'elle n'est pas confirmée.
     * Les comptes antérieurs à la fonctionnalité sont considérés comme confirmés (voir la migration).
     */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $emailVerifiedAt = null;

    /** SHA-256 du jeton de confirmation envoyé par email (le jeton lui-même n'est jamais stocké). */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $emailVerificationTokenHash = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $emailVerificationExpiresAt = null;

    #[ORM\OneToMany(mappedBy: 'user', targetEntity: Memory::class, orphanRemoval: true)]
    /** @var Collection<int, Memory> */
    private Collection $memories;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
        $this->memories = new ArrayCollection();
        $this->createdAt = new DateTimeImmutable();
        // garantit que chaque utilisateur a au moins ROLE_USER
        $this->roles = ['ROLE_USER'];
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;

        return $this;
    }

    /**
     * Identifiant visuel qui représente cet utilisateur.
     *
     * @see UserInterface
     */
    public function getUserIdentifier(): string
    {
        return (string) $this->email;
    }

    /**
     * @see UserInterface
     */
    public function getRoles(): array
    {
        $roles = $this->roles;

        return array_unique($roles);
    }

    public function setRoles(array $roles): static
    {
        $this->roles = $roles;

        return $this;
    }

    /**
     * @see PasswordAuthenticatedUserInterface
     */
    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;

        return $this;
    }

    /**
     * @see UserInterface
     */
    public function eraseCredentials(): void
    {
        // Si des données temporaires sensibles sont stockées sur l'utilisateur, les effacer ici
        // $this->plainPassword = null;
    }

    public function getPseudonym(): ?string
    {
        return $this->pseudonym;
    }

    public function setPseudonym(?string $pseudonym): static
    {
        $this->pseudonym = $pseudonym === null ? null : trim($pseudonym);

        return $this;
    }

    /**
     * Nom public affiché partout (souvenirs, commentaires, notifications, likers) : le pseudonyme.
     * Jamais l'email ni les rôles.
     */
    #[Groups(['get_user'])]
    public function getDisplayName(): string
    {
        return (string) $this->pseudonym;
    }

    public function getCredentialsChangedAt(): ?\DateTimeImmutable
    {
        return $this->credentialsChangedAt;
    }

    public function setCredentialsChangedAt(?\DateTimeImmutable $credentialsChangedAt): static
    {
        $this->credentialsChangedAt = $credentialsChangedAt;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getTermsAcceptedAt(): ?\DateTimeImmutable
    {
        return $this->termsAcceptedAt;
    }

    public function setTermsAcceptedAt(?\DateTimeImmutable $termsAcceptedAt): static
    {
        $this->termsAcceptedAt = $termsAcceptedAt;

        return $this;
    }

    public function getEmailVerifiedAt(): ?\DateTimeImmutable
    {
        return $this->emailVerifiedAt;
    }

    public function setEmailVerifiedAt(?\DateTimeImmutable $emailVerifiedAt): static
    {
        $this->emailVerifiedAt = $emailVerifiedAt;

        return $this;
    }

    #[Groups(['get_user_private'])]
    public function isEmailVerified(): bool
    {
        return $this->emailVerifiedAt !== null;
    }

    public function getEmailVerificationTokenHash(): ?string
    {
        return $this->emailVerificationTokenHash;
    }

    public function getEmailVerificationExpiresAt(): ?\DateTimeImmutable
    {
        return $this->emailVerificationExpiresAt;
    }

    /** Enregistre le haché du jeton envoyé (null, null pour l'effacer). */
    public function setEmailVerificationToken(?string $hash, ?\DateTimeImmutable $expiresAt): static
    {
        $this->emailVerificationTokenHash = $hash;
        $this->emailVerificationExpiresAt = $expiresAt;

        return $this;
    }

    /** Adresse modifiée : elle n'est plus confirmée, et l'ancien lien ne doit plus servir. */
    public function markEmailUnverified(): static
    {
        $this->emailVerifiedAt = null;

        return $this->setEmailVerificationToken(null, null);
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }

    /**
     * @return Collection<int, Memory>
     */
    public function getMemories(): Collection
    {
        return $this->memories;
    }

    public function addMemory(Memory $memory): static
    {
        if (!$this->memories->contains($memory)) {
            $this->memories->add($memory);
            $memory->setUser($this);
        }

        return $this;
    }

    public function removeMemory(Memory $memory): static
    {
        if ($this->memories->removeElement($memory)) {
            // met le côté propriétaire à null (sauf s'il a déjà changé)
            if ($memory->getUser() === $this) {
                $memory->setUser(null);
            }
        }

        return $this;
    }
}
