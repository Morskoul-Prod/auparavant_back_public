<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\Request;

/**
 * Objet (`reason`) et message libre (`message`) saisis par un modérateur dans un formulaire du back-office.
 * `$error` est renseigné (message lisible) quand l'un des deux est trop long : l'action ne doit alors pas être menée.
 */
final class ModerationInput
{
    private function __construct(
        public readonly ?string $reason,
        public readonly ?string $message,
        public readonly ?string $error = null,
    ) {
    }

    /**
     * @param string|null $defaultReason objet utilisé si le champ est absent du formulaire (un champ vidé donne null)
     */
    public static function fromRequest(Request $request, ?string $defaultReason = null): self
    {
        $reason = $request->request->has('reason') ? $request->request->all()['reason'] : $defaultReason;
        $message = $request->request->all()['message'] ?? null;

        return new self(
            ModerationNotifier::normalizeReason($reason),
            ModerationNotifier::normalizeMessage($message),
            ModerationNotifier::validationError($reason, $message),
        );
    }
}
