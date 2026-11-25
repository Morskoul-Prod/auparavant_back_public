<?php

namespace App\Controller\Api;

use App\Service\DeferredMailer;
use App\Service\EmailComposer;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mime\Address;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Avis des testeurs (formulaire « Donner mon avis »). Public : un visiteur peut écrire sans compte.
 * Le message n'est pas stocké, il est transmis par email à l'adresse de contact ; l'email facultatif de l'auteur
 * sert d'adresse de réponse (non vérifié : c'est un simple message, il ne donne accès à rien).
 */
#[OA\Tag(name: 'feedback')]
class FeedbackController extends AbstractController
{
    private const MESSAGE_MIN = 5;
    private const MESSAGE_MAX = 3000;

    #[Route('/api/feedback', name: 'api_feedback', methods: ['POST'])]
    #[OA\Post(summary: 'Envoie un avis à l\'équipe (message, email de réponse et page concernée facultatifs)')]
    #[OA\Response(response: 422, description: 'Message trop court ou trop long, email invalide')]
    #[OA\Response(response: 429, description: 'Trop d\'avis depuis cette adresse IP (5 par heure)')]
    public function send(
        Request $request,
        RateLimiterFactory $feedbackLimiter,
        EmailComposer $composer,
        DeferredMailer $mailer,
        #[Autowire('%mail_from_address%')] string $contactAddress,
    ): JsonResponse {
        $limit = $feedbackLimiter->create($request->getClientIp() ?? 'unknown')->consume();
        if (!$limit->isAccepted()) {
            return $this->json(
                ['message' => 'Trop d\'avis envoyés, réessayez plus tard.'],
                Response::HTTP_TOO_MANY_REQUESTS,
                ['Retry-After' => (string) max(1, $limit->getRetryAfter()->getTimestamp() - time())],
            );
        }

        $data = json_decode($request->getContent(), true);
        $data = is_array($data) ? $data : [];

        // Piège à robots : champ invisible pour un humain. Réponse normale, rien n'est envoyé.
        if (is_string($data['website'] ?? null) && trim($data['website']) !== '') {
            return $this->json(['message' => 'Merci pour votre avis !']);
        }

        $message = is_string($data['message'] ?? null) ? trim($data['message']) : '';
        $length = mb_strlen($message);
        if ($length < self::MESSAGE_MIN || $length > self::MESSAGE_MAX) {
            return $this->json(
                ['message' => sprintf('Votre message doit faire entre %d et %d caractères.', self::MESSAGE_MIN, self::MESSAGE_MAX)],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $email = is_string($data['email'] ?? null) ? trim($data['email']) : '';
        if ($email !== '' && (mb_strlen($email) > 180 || filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
            return $this->json(['message' => 'Cette adresse email n\'est pas valide.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $page = is_string($data['page'] ?? null) ? mb_substr(preg_replace('/[\r\n]+/', ' ', trim($data['page'])), 0, 200) : '';

        $mail = $composer->create(
            $contactAddress,
            'Avis sur la bêta d\'Auparavant',
            'emails/feedback.html.twig',
            'emails/feedback.txt.twig',
            ['message' => $message, 'author' => $email, 'page' => $page],
        );
        if ($email !== '') {
            $mail->replyTo(new Address($email));
        }
        $mailer->queue($mail);

        return $this->json(['message' => 'Merci pour votre avis !']);
    }
}
