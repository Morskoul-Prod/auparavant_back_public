<?php

namespace App\EventListener;

use App\Entity\User;
use App\Security\RequiresVerifiedEmail;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;

/**
 * Refuse les actions marquées #[RequiresVerifiedEmail] (création de contenu, quiz) tant que l'adresse email
 * de l'utilisateur n'est pas confirmée. L'équipe de modération en est dispensée (comptes créés par un administrateur).
 */
final class VerifiedEmailListener
{
    public function __construct(private readonly Security $security)
    {
    }

    #[AsEventListener(event: 'kernel.controller_arguments')]
    public function onController(ControllerArgumentsEvent $event): void
    {
        if (!$event->getAttributes(RequiresVerifiedEmail::class)) {
            return;
        }

        $user = $this->security->getUser();
        if (!$user instanceof User || $user->isEmailVerified() || in_array('ROLE_MODERATOR', $user->getRoles(), true)) {
            return;
        }

        $event->setController(static fn (): JsonResponse => new JsonResponse([
            'message' => 'Confirmez votre adresse email pour effectuer cette action.',
            'code' => 'email_not_verified',
        ], Response::HTTP_FORBIDDEN));
    }
}
