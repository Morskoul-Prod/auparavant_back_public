<?php

namespace App\Entity;

/**
 * « J'aime » d'un utilisateur sur un contenu (commentaire ou souvenir). Chaque type garde sa propre table, pour
 * conserver les clés étrangères et leur suppression en cascade.
 */
interface LikeInterface
{
    public function getId(): ?int;

    public function getUser(): ?User;

    public function getCreatedAt(): \DateTimeImmutable;

    /** Contenu aimé */
    public function getTarget(): Comment|Memory|null;
}
