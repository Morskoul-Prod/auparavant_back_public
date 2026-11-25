<?php

namespace App\EventListener;

use App\Exception\ApiResponseException;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;

/**
 * Renvoie la réponse portée par une `ApiResponseException` levée dans un service ou un contrôleur.
 */
#[AsEventListener(event: 'kernel.exception')]
final class ApiResponseExceptionListener
{
    public function __invoke(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();
        if ($exception instanceof ApiResponseException) {
            $event->setResponse($exception->response);
        }
    }
}
