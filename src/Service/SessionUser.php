<?php

namespace App\Service;

use App\Entity\User;

/**
 * Bloc `data` des réponses de session (/api/login_check, /api/token/refresh) et réponse de /api/secure/me :
 * identité, rôles et état de l'adresse email de l'utilisateur connecté uniquement.
 */
final class SessionUser
{
    public function __construct(private readonly EmailChangeService $emailChanges)
    {
    }

    /** @return array{id: int|null, pseudonym: string|null, email: string|null, roles: list<string>, emailVerified: bool, pendingEmail: string|null} */
    public function payload(User $user): array
    {
        return [
            'id' => $user->getId(),
            'pseudonym' => $user->getPseudonym(),
            'email' => $user->getEmail(),
            'roles' => $user->getRoles(),
            'emailVerified' => $user->isEmailVerified(),
            // Nouvelle adresse en attente de confirmation (l'adresse du compte reste `email` jusque-là)
            'pendingEmail' => $this->emailChanges->pendingEmail($user),
        ];
    }
}
