<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\RawMessage;

/**
 * Envoi des emails transactionnels APRÈS l'envoi de la réponse HTTP (événement kernel.terminate), sans worker :
 * - l'utilisateur n'attend pas l'API du fournisseur ;
 * - la durée de la réponse ne dépend pas de l'existence du compte (pas d'énumération par le temps de réponse) ;
 * - un échec d'envoi (fournisseur indisponible) est journalisé, il ne devient pas une erreur 500 qui, elle, trahirait
 *   l'existence du compte. L'utilisateur peut redemander le lien.
 * Réservé aux requêtes HTTP : hors requête (commande console), le terminate ne se produit pas.
 */
class DeferredMailer
{
    /** @var list<RawMessage> */
    private array $pending = [];

    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function queue(RawMessage $message): void
    {
        $this->pending[] = $message;
    }

    #[AsEventListener(event: KernelEvents::TERMINATE)]
    public function flush(): void
    {
        $messages = $this->pending;
        $this->pending = [];

        foreach ($messages as $message) {
            try {
                $this->mailer->send($message);
            } catch (\Throwable $e) {
                $this->logger->error('Envoi d\'email impossible : ' . $e->getMessage(), ['exception' => $e]);
            }
        }
    }
}
