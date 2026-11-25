<?php

namespace App\EventListener;

use App\Entity\User;
use App\Repository\UserRepository;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTCreatedEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTDecodedEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Invalide les anciens JWT après un changement d'email ou de mot de passe : le jeton porte le claim « cca »
 * (credentialsChangedAt en timestamp, 0 si jamais changé) qui doit être égal à la valeur actuelle du compte.
 * Un jeton émis avant le déploiement n'a pas le claim : il vaut 0, donc reste valable tant que rien n'a changé.
 * Limite : coût d'une requête SQL supplémentaire par appel authentifié ; un jeton volé reste valable
 * jusqu'au prochain changement d'email ou de mot de passe (pas de liste de révocation).
 */
final class JwtCredentialsListener
{
    public function __construct(private readonly UserRepository $users)
    {
    }

    #[AsEventListener(event: 'lexik_jwt_authentication.on_jwt_created')]
    public function onCreated(JWTCreatedEvent $event): void
    {
        $user = $event->getUser();
        if (!$user instanceof User) {
            return;
        }

        $payload = $event->getData();
        $payload['cca'] = $user->getCredentialsChangedAt()?->getTimestamp() ?? 0;
        $event->setData($payload);
    }

    #[AsEventListener(event: 'lexik_jwt_authentication.on_jwt_decoded')]
    public function onDecoded(JWTDecodedEvent $event): void
    {
        $payload = $event->getPayload();
        $user = $this->users->findOneBy(['email' => $payload['username'] ?? '']);

        // Email changé depuis l'émission, compte supprimé, ou identifiants modifiés : jeton refusé
        if (!$user || ($payload['cca'] ?? 0) !== ($user->getCredentialsChangedAt()?->getTimestamp() ?? 0)) {
            $event->markAsInvalid();
        }
    }
}
