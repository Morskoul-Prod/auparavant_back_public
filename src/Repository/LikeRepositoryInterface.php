<?php

namespace App\Repository;

use App\Entity\Comment;
use App\Entity\LikeInterface;
use App\Entity\Memory;
use App\Entity\User;

/**
 * Requêtes communes aux likes de commentaires et de souvenirs (voir `LikeNotifier`).
 */
interface LikeRepositoryInterface
{
    public function countForTarget(Comment|Memory $target): int;

    /** Nombre de likes d'id supérieur ou égal à `$fromId`, hors ceux de `$excludedAuthor` */
    public function countSince(Comment|Memory $target, int $fromId, User $excludedAuthor): int;

    /** Dernier like (id le plus élevé) dans les mêmes conditions que `countSince()` */
    public function findLastSince(Comment|Memory $target, int $fromId, User $excludedAuthor): ?LikeInterface;

    /** Supprime tous les likes d'un utilisateur (avant la suppression de son compte) */
    public function deleteForUser(User $user): void;

    /**
     * Utilisateurs ayant aimé, du like le plus récent au plus ancien (une requête, utilisateur joint)
     *
     * @return User[]
     */
    public function findLikers(Comment|Memory $target, int $limit): array;
}
