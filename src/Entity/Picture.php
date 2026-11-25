<?php

namespace App\Entity;

use App\Enum\PictureStatus;
use App\Repository\PictureRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Vich\UploaderBundle\Mapping\Attribute as Vich;

#[ORM\Entity(repositoryClass: PictureRepository::class)]
#[Vich\Uploadable] // Active VichUploader
class Picture
{
    public const TYPE_BEFORE = 'before';
    public const TYPE_AFTER = 'after';
    public const TYPE_OTHER = 'other';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['get_picture', 'get_memory'])]
    private ?int $id = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['get_picture', 'get_memory'])]
    private ?string $imageName = null;

    // ✅ AJOUTER : Fichier uploadé (géré par VichUploader, non persisté en BDD)
    #[Vich\UploadableField(mapping: 'memory_pictures', fileNameProperty: 'imageName')]
    private ?File $imageFile = null;

    #[ORM\Column(options: ['default' => 0])]
    #[Groups(['get_picture', 'get_memory'])]
    private int $position = 0;

    /**
     * Type de l'image : 'before' (avant), 'after' (après), 'other' (autre)
     */
    #[ORM\Column(length: 20, nullable: true)]
    #[Groups(['get_picture', 'get_memory'])]
    private ?string $type = self::TYPE_OTHER;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\ManyToOne(inversedBy: 'pictures', cascade: ['persist'])]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['get_memory_id'])]
    private ?Memory $memory = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['get_picture', 'get_memory'])]
    private ?string $copyrightHolder = null;

    /**
     * Statut de modération : seules les photos approuvées sont visibles publiquement
     */
    #[ORM\Column(length: 20, enumType: PictureStatus::class, options: ['default' => 'approved'])]
    #[Groups(['get_picture', 'get_memory'])]
    private PictureStatus $status = PictureStatus::APPROVED;

    /**
     * Utilisateur qui a envoyé la photo (null pour les photos antérieures à la modération).
     * Exposé uniquement via son id (voir getUploadedById), jamais l'utilisateur complet.
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $uploadedBy = null;

    /**
     * Motif du refus (lisible), renseigné à la modération. Jamais exposé par les groupes de sérialisation :
     * seul l'auteur le reçoit (notifications, détail d'un souvenir).
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $rejectionReason = null;

    /**
     * Date de la décision de modération (approbation ou rejet)
     */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $moderatedAt = null;

    /**
     * Date à laquelle l'auteur a pris connaissance du refus (null tant que la notification n'est pas vue)
     */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $rejectionSeenAt = null;

    /**
     * Photo actuelle approuvée que cette proposition remplacera à sa validation (null pour un ajout simple).
     * Remise à null si l'ancienne photo disparaît.
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Picture $replaces = null;

    /**
     * Pour une proposition de photo actuelle : photo de la galerie que son auteur suggère comme photo « avant »
     * du comparateur. Appliquée à l'approbation si le souvenir n'a toujours pas de photo « avant ».
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Picture $suggestedBefore = null;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
    }

    // =============== Getters/Setters basiques ===============

    public function getId(): ?int
    {
        return $this->id;
    }

    // =============== Méthodes VichUploader ===============

    /**
     * Setter pour le fichier uploadé
     */
    public function setImageFile(?File $imageFile = null): void
    {
        $this->imageFile = $imageFile;

        if ($imageFile) {
            // Force Doctrine à détecter le changement
            $this->updatedAt = new \DateTimeImmutable();
        }
    }

    public function getImageFile(): ?File
    {
        return $this->imageFile;
    }

    public function setImageName(?string $imageName): void
    {
        $this->imageName = $imageName;
    }

    public function getImageName(): ?string
    {
        return $this->imageName;
    }

    // =============== Méthodes de compatibilité (pour ne pas casser le code existant) ===============

    // /**
    //  * @deprecated Utiliser getImageName() à la place
    //  */
    // public function getPicture(): ?string
    // {
    //     return $this->imageName;
    // }

    // /**
    //  * @deprecated Utiliser setImageName() à la place
    //  */
    // public function setPicture(?string $picture): self
    // {
    //     $this->imageName = $picture;
    //     return $this;
    // }

    // =============== Helpers style Spatie ===============

    /**
     * Retourne l'URL relative de l'image
     * Exemple: /uploads/memories/abc123.jpg
     */
    #[Groups(['get_picture', 'get_memory'])]
    public function getImageUrl(): ?string
    {
        return $this->imageName
            ? '/uploads/memories/' . $this->imageName
            : null;
    }

    /**
     * Retourne l'URL complète de l'image
     * Exemple: https://monsite.com/uploads/memories/abc123.jpg
     */
    public function getImageFullUrl(string $baseUrl): ?string
    {
        return $this->imageName
            ? rtrim($baseUrl, '/') . $this->getImageUrl()
            : null;
    }

    // =============== Type de photo (avant/après/autre) ===============

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(?string $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function isBefore(): bool
    {
        return $this->type === self::TYPE_BEFORE;
    }

    public function isAfter(): bool
    {
        return $this->type === self::TYPE_AFTER;
    }

    /**
     * Valeurs de type acceptées par l'API (liste blanche)
     *
     * @return list<string>
     */
    public static function getAllowedTypes(): array
    {
        return [self::TYPE_BEFORE, self::TYPE_AFTER, self::TYPE_OTHER];
    }

    /**
     * Labels des types pour l'affichage
     */
    public static function getTypeChoices(): array
    {
        return [
            'Photo avant' => self::TYPE_BEFORE,
            'Photo après (actuelle)' => self::TYPE_AFTER,
            'Non défini' => self::TYPE_OTHER,
        ];
    }

    // =============== Modération ===============

    public function getStatus(): PictureStatus
    {
        return $this->status;
    }

    public function setStatus(PictureStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function isApproved(): bool
    {
        return $this->status === PictureStatus::APPROVED;
    }

    public function getUploadedBy(): ?User
    {
        return $this->uploadedBy;
    }

    public function setUploadedBy(?User $uploadedBy): static
    {
        $this->uploadedBy = $uploadedBy;

        return $this;
    }

    /**
     * Id de l'auteur de l'envoi (clé JSON « uploadedBy »), sans exposer d'autre donnée utilisateur
     */
    #[Groups(['get_picture', 'get_memory'])]
    #[SerializedName('uploadedBy')]
    public function getUploadedById(): ?int
    {
        return $this->uploadedBy?->getId();
    }

    public function getReplaces(): ?Picture
    {
        return $this->replaces;
    }

    public function setReplaces(?Picture $replaces): static
    {
        $this->replaces = $replaces;

        return $this;
    }

    /**
     * Id de la photo actuelle que cette proposition remplacera (clé JSON « replacesId »), null sinon.
     * Permet au front de repérer un remplacement en attente.
     */
    #[Groups(['get_picture', 'get_memory'])]
    #[SerializedName('replacesId')]
    public function getReplacesId(): ?int
    {
        return $this->replaces?->getId();
    }

    public function getSuggestedBefore(): ?Picture
    {
        return $this->suggestedBefore;
    }

    public function setSuggestedBefore(?Picture $suggestedBefore): static
    {
        $this->suggestedBefore = $suggestedBefore;

        return $this;
    }

    /**
     * Id de la photo suggérée comme « avant » par cette proposition (clé JSON « suggestedBeforeId »), null sinon.
     */
    #[Groups(['get_picture', 'get_memory'])]
    #[SerializedName('suggestedBeforeId')]
    public function getSuggestedBeforeId(): ?int
    {
        return $this->suggestedBefore?->getId();
    }

    public function isRejected(): bool
    {
        return $this->status === PictureStatus::REJECTED;
    }

    public function getRejectionReason(): ?string
    {
        return $this->rejectionReason;
    }

    public function setRejectionReason(?string $rejectionReason): static
    {
        $this->rejectionReason = $rejectionReason;

        return $this;
    }

    public function getModeratedAt(): ?\DateTimeImmutable
    {
        return $this->moderatedAt;
    }

    public function setModeratedAt(?\DateTimeImmutable $moderatedAt): static
    {
        $this->moderatedAt = $moderatedAt;

        return $this;
    }

    public function getRejectionSeenAt(): ?\DateTimeImmutable
    {
        return $this->rejectionSeenAt;
    }

    public function setRejectionSeenAt(?\DateTimeImmutable $rejectionSeenAt): static
    {
        $this->rejectionSeenAt = $rejectionSeenAt;

        return $this;
    }

    /**
     * Refus non encore vu par son auteur (donc à notifier)
     */
    public function isUnseenRejectionOf(?User $user): bool
    {
        return $user !== null
            && $this->isRejected()
            && $this->rejectionSeenAt === null
            && $this->uploadedBy === $user;
    }

    // =============== Nouvelles propriétés ===============

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

        return $this;
    }

    // =============== Timestamps ===============

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
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

    // =============== Relations ===============

    public function getMemory(): ?Memory
    {
        return $this->memory;
    }

    public function setMemory(?Memory $memory): static
    {
        $this->memory = $memory;

        return $this;
    }

    public function getCopyrightHolder(): ?string
    {
        return $this->copyrightHolder;
    }

    public function setCopyrightHolder(?string $copyrightHolder): static
    {
        $this->copyrightHolder = $copyrightHolder;

        return $this;
    }
}
