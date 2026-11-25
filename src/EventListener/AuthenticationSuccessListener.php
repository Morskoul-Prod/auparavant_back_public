<?php

namespace App\EventListener;

use App\Entity\User;
use App\Service\RefreshTokenManager;
use App\Service\SessionUser;
use Lexik\Bundle\JWTAuthenticationBundle\Event\AuthenticationSuccessEvent;

class AuthenticationSuccessListener
{
    public function __construct(
        private readonly RefreshTokenManager $refreshTokens,
        private readonly SessionUser $sessionUser,
    ) {
    }

    /**
     * Front-end authentication management : identité de l'utilisateur dans la réponse
     * et refresh token en cookie HttpOnly (le JWT, lui, reste dans le corps)
     * @param AuthenticationSuccessEvent $event
     */
    public function onAuthenticationSuccessResponse(AuthenticationSuccessEvent $event)
    {
        $data = $event->getData();
        $user = $event->getUser();

        if (!$user instanceof User) {
            return;
        }

        // Rôles de l'utilisateur connecté uniquement (le front en déduit les actions de modération)
        $data['data'] = $this->sessionUser->payload($user);
        $event->setData($data);

        $this->refreshTokens->attach($event->getResponse(), $user);
    }
}
