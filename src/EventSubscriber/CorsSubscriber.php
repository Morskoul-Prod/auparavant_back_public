<?php

namespace App\EventSubscriber;

use App\Service\AllowedOrigins;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Gère les CORS (Cross-Origin Resource Sharing) pour les requêtes API
 */
class CorsSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly AllowedOrigins $allowedOrigins)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 250],
            KernelEvents::RESPONSE => ['onKernelResponse', 0],
        ];
    }

    /**
     * Gère les requêtes OPTIONS (preflight)
     */
    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        // Gestion des requêtes preflight OPTIONS
        if ($request->getMethod() === 'OPTIONS') {
            $response = new Response();
            $response->setStatusCode(Response::HTTP_OK);

            $origin = $request->headers->get('Origin');
            if ($origin && $this->isOriginAllowed($origin)) {
                $response->headers->set('Access-Control-Allow-Origin', $origin);
                $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS');
                $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With');
                $response->headers->set('Access-Control-Allow-Credentials', 'true');
                $response->headers->set('Access-Control-Max-Age', '3600');
            }

            $event->setResponse($response);
        }
    }

    /**
     * Ajoute les headers CORS à toutes les réponses
     */
    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $response = $event->getResponse();

        $origin = $request->headers->get('Origin');

        if ($origin && $this->isOriginAllowed($origin)) {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
            $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS');
            $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With');
            $response->headers->set('Access-Control-Allow-Credentials', 'true');
            // Lisible par le front (sinon invisible en CORS) : délai d'attente des réponses 429
            $response->headers->set('Access-Control-Expose-Headers', 'Retry-After');
        }
    }

    /**
     * Vérifie si l'origine est autorisée
     */
    private function isOriginAllowed(string $origin): bool
    {
        return $this->allowedOrigins->contains($origin);
    }
}
