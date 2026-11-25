<?php

namespace App\Service;

use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mime\Address;

/**
 * Construit les emails transactionnels (expéditeur unique, version HTML et version texte).
 * L'envoi se fait ensuite par DeferredMailer::queue().
 */
class EmailComposer
{
    public function __construct(
        #[Autowire('%mail_from_address%')] private readonly string $fromAddress,
        #[Autowire('%mail_from_name%')] private readonly string $fromName,
    ) {
    }

    /** @param array<string, mixed> $context variables des deux gabarits */
    public function create(string $to, string $subject, string $htmlTemplate, string $textTemplate, array $context = []): TemplatedEmail
    {
        return (new TemplatedEmail())
            ->from(new Address($this->fromAddress, $this->fromName))
            ->to($to)
            ->subject($subject)
            ->htmlTemplate($htmlTemplate)
            ->textTemplate($textTemplate)
            ->context($context);
    }
}
