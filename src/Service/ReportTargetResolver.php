<?php

namespace App\Service;

use App\Entity\Comment;
use App\Entity\Memory;
use App\Entity\Picture;
use App\Entity\User;
use App\Enum\ReportTargetType;
use App\Repository\CommentRepository;
use App\Repository\MemoryRepository;
use App\Repository\PictureRepository;

/**
 * Retrouve la cible d'un signalement et son auteur.
 */
class ReportTargetResolver
{
    public function __construct(
        private readonly CommentRepository $commentRepository,
        private readonly MemoryRepository $memoryRepository,
        private readonly PictureRepository $pictureRepository,
    ) {
    }

    /**
     * Cible qu'un utilisateur peut signaler, sinon null (inexistante ou non visible publiquement) :
     * commentaire public (ni supprimé ni masqué), souvenir, photo approuvée (une photo en attente ou refusée
     * n'est visible que de son auteur).
     */
    public function findReportable(ReportTargetType $type, int $id): ?object
    {
        $target = $this->find($type, $id);

        return match (true) {
            $target instanceof Comment => $target->isPublic() ? $target : null,
            $target instanceof Picture => $target->isApproved() ? $target : null,
            default => $target,
        };
    }

    /** Cible quel que soit son état (null si elle n'existe plus) */
    public function find(ReportTargetType $type, int $id): ?object
    {
        return match ($type) {
            ReportTargetType::COMMENT => $this->commentRepository->find($id),
            ReportTargetType::MEMORY => $this->memoryRepository->find($id),
            ReportTargetType::PICTURE => $this->pictureRepository->find($id),
        };
    }

    public function getAuthor(object $target): ?User
    {
        return match (true) {
            $target instanceof Comment => $target->getAuthor(),
            $target instanceof Memory => $target->getUser(),
            $target instanceof Picture => $target->getUploadedBy(),
            default => null,
        };
    }
}
