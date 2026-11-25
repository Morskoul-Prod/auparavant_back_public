<?php

namespace App\Service;

use App\Entity\Picture;
use App\Entity\QuizRound;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Vich\UploaderBundle\Mapping\PropertyMappingFactory;

/**
 * Photos des manches, servies par une URL signée propre au quiz (`/api/quiz/picture/{id}?exp=…&sig=…`).
 *
 * L'URL publique du fichier (`/uploads/memories/<nom>`) est la même que dans `GET /api/memories` : la donner au
 * joueur lui permettrait de retrouver le souvenir, donc sa réponse. L'URL signée n'expose que l'id de la manche
 * (pas celui du souvenir) ni le nom du fichier. Elle est valable `LIFETIME` secondes : le front la reçoit à chaque
 * appel de l'état du quiz et ne doit pas la conserver. Signature HMAC avec `kernel.secret`, sans JWT, pour qu'une
 * balise <img> puisse la charger directement.
 */
class QuizPictures
{
    public const LIFETIME = 3600;

    public function __construct(
        #[Autowire('%kernel.secret%')] private readonly string $secret,
        private readonly PropertyMappingFactory $vichMappings,
    ) {
    }

    /** URL relative signée de la photo d'une manche */
    public function url(QuizRound $round): string
    {
        $expires = time() + self::LIFETIME;

        return sprintf('/api/quiz/picture/%d?exp=%d&sig=%s', $round->getId(), $expires, $this->signature($round->getId(), $expires));
    }

    public function isValid(int $roundId, int $expires, string $signature): bool
    {
        return $expires >= time() && hash_equals($this->signature($roundId, $expires), $signature);
    }

    /** Chemin du fichier sur le disque (même dossier que VichUploader), null s'il n'existe plus */
    public function path(QuizRound $round): ?string
    {
        $name = basename($round->getPictureName());
        if ($name === '') {
            return null;
        }
        $directory = $this->vichMappings->fromField(new Picture(), 'imageFile')->getUploadDestination();
        $path = $directory . '/' . $name;

        return is_file($path) ? $path : null;
    }

    private function signature(int $roundId, int $expires): string
    {
        return hash_hmac('sha256', 'quiz-picture|' . $roundId . '|' . $expires, $this->secret);
    }
}
