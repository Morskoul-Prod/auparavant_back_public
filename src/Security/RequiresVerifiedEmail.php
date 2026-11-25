<?php

namespace App\Security;

/**
 * Réserve une action (méthode de contrôleur, ou classe entière) aux comptes dont l'adresse email est confirmée.
 * Sinon : 403 avec `code: email_not_verified` (voir EventListener/VerifiedEmailListener).
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::TARGET_CLASS)]
final class RequiresVerifiedEmail
{
}
