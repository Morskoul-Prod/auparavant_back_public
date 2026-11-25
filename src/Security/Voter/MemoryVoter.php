<?php

namespace App\Security\Voter;

use App\Entity\Memory;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Droits sur un souvenir via l'API : modification (photos comprises) et suppression sont réservées à l'auteur.
 * La suppression par un administrateur ou un modérateur se fait exclusivement depuis le back-office
 * (rôle contrôlé par `access_control` et `#[IsGranted]`, CSRF, motif communiqué à l'auteur) : ils ne passent
 * donc pas par ce voter.
 */
class MemoryVoter extends Voter
{
    public const EDIT = 'MEMORY_EDIT';
    public const DELETE = 'MEMORY_DELETE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::EDIT, self::DELETE], true) && $subject instanceof Memory;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        return $subject->getUser()?->getId() === $user->getId();
    }
}
