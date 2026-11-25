<?php

namespace App\Entity;

use App\Enum\PictureStatus;
use App\Repository\MemoryRepository;
use App\Service\Slug;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[ORM\Entity(repositoryClass: MemoryRepository::class)]
class Memory
{
    public const MIN_YEAR = 1500;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['get_memory'])]
    private ?int $id = null;

    #[ORM\Column(length: 50)]
    #[Assert\NotBlank]
    #[Assert\Length(
        min: 2,
        max: 50,
        minMessage: 'Le titre doit comporter au moins {{ limit }} caractères.',
        maxMessage: 'Le titre ne peut pas dépasser {{ limit }} caractères.',
    )]
    #[Groups(['get_memory'])]
    private ?string $title = null;

    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank]
    #[Assert\Length(
        min: 10,
        max: 64000,
        minMessage: 'Le contenu doit comporter au moins {{ limit }} caractères.',
        maxMessage: 'Le contenu ne peut pas dépasser {{ limit }} caractères.',
    )]
    #[Groups(['get_memory'])]
    private ?string $content = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    #[Groups(['get_memory'])]
    private ?\DateTimeInterface $picture_date = null;

    /**
     * Année approximative de la photo (utile quand la date exacte n'est pas connue)
     * Utilisé pour la fresque chronologique
     */
    #[ORM\Column(nullable: true)]
    #[Groups(['get_memory'])]
    private ?int $year = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\ManyToOne(inversedBy: 'memories')]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['get_memory'])]
    private ?Location $location = null;

    #[ORM\OneToMany(
        mappedBy: 'memory',
        targetEntity: Picture::class,
        orphanRemoval: true,
        cascade: ['persist', 'remove']
    )]
    #[ORM\OrderBy(['position' => 'ASC'])]
    /** @var Collection<int, Picture> */
    private Collection $pictures;

    /** @var Collection<int, Comment> */
    #[ORM\OneToMany(mappedBy: 'memory', targetEntity: Comment::class, orphanRemoval: true)]
    private Collection $comments;

    #[ORM\ManyToOne(inversedBy: 'memories')]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['get_memory'])]
    private ?User $user = null;

    #[ORM\ManyToOne(inversedBy: 'memories')]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['get_memory'])]
    private ?Place $place = null;

    /** Non persisté : renseigné par `MemoryLikeCounts` pour les listes (groupe `get_memory_likes`) */
    private int $likesCount = 0;

    /** Non persisté : commentaires publics (ni masqués ni supprimés), renseigné par `MemoryLikeCounts` (groupe `get_memory_likes`) */
    private int $commentsCount = 0;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
        $this->pictures = new ArrayCollection();
        $this->comments = new ArrayCollection();
    }

    /**
     * @return Collection<int, Comment>
     */
    public function getComments(): Collection
    {
        return $this->comments;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * Partie lisible de l'adresse du souvenir (titre + ville), à placer après l'id : /souvenirs/{id}-{slug}.
     * Non stockée : l'id fait foi, le slug suit le titre et la ville.
     */
    #[Groups(['get_memory'])]
    public function getSlug(): string
    {
        return Slug::ofMemory($this->title, $this->location?->getCity());
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getContent(): ?string
    {
        return $this->content;
    }

    public function setContent(string $content): static
    {
        $this->content = $content;

        return $this;
    }

    public function getPictureDate(): ?\DateTimeInterface
    {
        return $this->picture_date;
    }

    public function setPictureDate(?\DateTimeInterface $picture_date): static
    {
        $this->picture_date = $picture_date;

        // Met à jour automatiquement l'année si une date est fournie
        // (passer null conserve l'année existante : bascule date exacte -> année seule)
        if ($picture_date !== null) {
            $this->year = (int) $picture_date->format('Y');
        }

        return $this;
    }

    /**
     * Année effective : celle de la date exacte si elle existe, sinon l'année saisie.
     * C'est cette valeur qui est sérialisée (clé `year`) pour le front.
     */
    public function getYear(): ?int
    {
        return $this->getEffectiveYear();
    }

    /**
     * Quand une date exacte est renseignée, l'année en est déduite (voir setPictureDate) :
     * un year transmis en parallèle est alors ignoré pour ne jamais contredire la date.
     */
    public function setYear(?int $year): static
    {
        if ($this->picture_date === null) {
            $this->year = $year;
        }

        return $this;
    }

    /**
     * Retourne l'année effective (de la date ou du champ year)
     * Utile pour la fresque chronologique
     */
    public function getEffectiveYear(): ?int
    {
        if ($this->picture_date !== null) {
            return (int) $this->picture_date->format('Y');
        }

        return $this->year;
    }

    public function getPublishedAt(): ?\DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function setPublishedAt(?\DateTimeImmutable $publishedAt): static
    {
        $this->publishedAt = $publishedAt;

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

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }

    public function getLocation(): ?Location
    {
        return $this->location;
    }

    public function setLocation(?Location $location): static
    {
        $this->location = $location;

        return $this;
    }

    /**
     * @return Collection<int, Picture>
     */
    public function getPictures(): Collection
    {
        return $this->pictures;
    }

    /**
     * Photos approuvées, seules exposées publiquement (clé JSON « pictures »)
     *
     * @return list<Picture>
     */
    #[Groups(['get_memory'])]
    #[SerializedName('pictures')]
    public function getApprovedPictures(): array
    {
        return array_values($this->pictures->filter(fn (Picture $p) => $p->isApproved())->toArray());
    }

    /**
     * Photos approuvées + propositions en attente de l'utilisateur donné et ses refus non encore vus
     * (et seulement les siennes)
     *
     * @return list<Picture>
     */
    public function getPicturesVisibleBy(?User $user): array
    {
        return array_values($this->pictures->filter(
            fn (Picture $p) => $p->isApproved()
                || ($user !== null && $p->getStatus() === PictureStatus::PENDING && $p->getUploadedBy() === $user)
                || $p->isUnseenRejectionOf($user)
        )->toArray());
    }

    /**
     * Photos anciennes approuvées (hors photo actuelle « after »), par position croissante :
     * la première sert de référence « avant » du comparateur de modération.
     *
     * @return list<Picture>
     */
    public function getApprovedOlderPictures(): array
    {
        return array_values(array_filter($this->getApprovedPictures(), fn (Picture $p) => !$p->isAfter()));
    }

    /**
     * Photo actuelle (type « after ») approuvée, s'il y en a une
     */
    public function getApprovedAfterPicture(): ?Picture
    {
        foreach ($this->pictures as $picture) {
            if ($picture->isApproved() && $picture->isAfter()) {
                return $picture;
            }
        }

        return null;
    }

    /**
     * Proposition de photo actuelle en attente de l'utilisateur donné, s'il en a une
     */
    public function getPendingAfterPictureOf(User $user): ?Picture
    {
        foreach ($this->pictures as $picture) {
            if ($picture->getStatus() === PictureStatus::PENDING && $picture->isAfter() && $picture->getUploadedBy() === $user) {
                return $picture;
            }
        }

        return null;
    }

    public function addPicture(Picture $picture): static
    {
        if (!$this->pictures->contains($picture)) {
            $this->pictures->add($picture);
            $picture->setMemory($this);
        }

        return $this;
    }

    public function removePicture(Picture $picture): static
    {
        if ($this->pictures->removeElement($picture)) {
            // met le côté propriétaire à null (sauf s'il a déjà changé)
            if ($picture->getMemory() === $this) {
                $picture->setMemory(null);
            }
        }

        return $this;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getPlace(): ?Place
    {
        return $this->place;
    }

    public function setPlace(?Place $place): static
    {
        $this->place = $place;

        return $this;
    }

    #[Assert\Callback]
    public function validatePictures(ExecutionContextInterface $context): void
    {
        // Seules les photos approuvées comptent : un souvenir doit avoir au moins une photo visible
        $picturesCount = count($this->getApprovedPictures());

        // On est en création si l'entité n'a pas d'ID
        $isNew = $this->getId() === null;

        if ($isNew && $picturesCount === 0) {
            $context->buildViolation('Vous devez ajouter au moins une photo.')
                ->atPath('pictures')
                ->addViolation();
        }

        // En édition : au moins une photo doit rester
        if (!$isNew && $picturesCount === 0) {
            $context->buildViolation('Un souvenir doit contenir au moins une photo.')
                ->atPath('pictures')
                ->addViolation();
        }
    }

    /**
     * Une date exacte ou, à défaut, une année est obligatoire ; l'année doit être plausible, et la date exacte
     * ne peut pas être dans le futur.
     */
    #[Assert\Callback]
    public function validateDateOrYear(ExecutionContextInterface $context): void
    {
        $year = $this->getEffectiveYear();

        if ($year === null) {
            $context->buildViolation('Indiquez une date exacte ou, à défaut, une année.')
                ->atPath('picture_date')
                ->addViolation();

            return;
        }

        $maxYear = (int) date('Y');
        if ($year < self::MIN_YEAR || $year > $maxYear) {
            $context->buildViolation(sprintf('L\'année doit être comprise entre %d et %d.', self::MIN_YEAR, $maxYear))
                ->atPath('year')
                ->addViolation();
        }

        // Tolérance d'un jour : la date saisie est celle du fuseau du visiteur, qui peut être en avance sur le serveur
        if ($this->picture_date !== null && $this->picture_date > new \DateTime('tomorrow +1 day')) {
            $context->buildViolation('La date du souvenir ne peut pas être dans le futur.')
                ->atPath('picture_date')
                ->addViolation();
        }
    }

    #[Groups(['get_memory_likes'])]
    public function getLikesCount(): int
    {
        return $this->likesCount;
    }

    public function setLikesCount(int $likesCount): static
    {
        $this->likesCount = $likesCount;

        return $this;
    }

    #[Groups(['get_memory_likes'])]
    public function getCommentsCount(): int
    {
        return $this->commentsCount;
    }

    public function setCommentsCount(int $commentsCount): static
    {
        $this->commentsCount = $commentsCount;

        return $this;
    }
}
