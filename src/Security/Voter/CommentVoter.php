<?php

namespace App\Security\Voter;

use App\Entity\Comment;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Droits sur un commentaire : modifier = auteur seul ; supprimer = auteur, administrateur ou modérateur.
 * Un commentaire déjà supprimé n'est plus modifiable ni supprimable ; un commentaire masqué n'est plus modifiable.
 */
class CommentVoter extends Voter
{
    public const EDIT = 'COMMENT_EDIT';
    public const DELETE = 'COMMENT_DELETE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::EDIT, self::DELETE], true) && $subject instanceof Comment;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User || $subject->isDeleted()) {
            return false;
        }

        $isAuthor = $subject->getAuthor() !== null && $subject->getAuthor()->getId() === $user->getId();

        return match ($attribute) {
            self::EDIT => $isAuthor && !$subject->isHidden(),
            self::DELETE => $isAuthor || in_array('ROLE_ADMIN', $user->getRoles(), true) || in_array('ROLE_MODERATOR', $user->getRoles(), true),
            default => false,
        };
    }
}
