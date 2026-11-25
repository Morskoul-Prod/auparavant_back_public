<?php

namespace App\Controller\Api;

use App\Entity\Memory;
use App\Entity\QuizRound;
use App\Entity\User;
use App\Exception\QuizException;
use App\Security\RequiresVerifiedEmail;
use App\Service\ApiValidationErrorResponder;
use App\Service\QuizClock;
use App\Service\QuizGameService;
use App\Service\QuizLeaderboard;
use App\Service\QuizPictures;
use App\Service\QuizPresenter;
use App\Service\QuizStats;
use Doctrine\ORM\EntityManagerInterface;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Quiz du jour : 5 manches (une photo, un marker sur la carte, une année), les mêmes souvenirs pour tous.
 * Avant la réponse, une manche n'expose que son numéro et sa photo (voir QuizPresenter).
 */
#[OA\Schema(
    schema: 'QuizRoundResult',
    description: 'Correction d\'une manche répondue',
    properties: [
        new OA\Property(property: 'position', type: 'integer', example: 1),
        new OA\Property(property: 'pictureUrl', type: 'string', example: '/api/quiz/picture/12?exp=1793700000&sig=9f2c…'),
        new OA\Property(property: 'truth', properties: [
            new OA\Property(property: 'lat', type: 'number', format: 'float'),
            new OA\Property(property: 'lng', type: 'number', format: 'float'),
            new OA\Property(property: 'year', type: 'integer'),
        ], type: 'object'),
        new OA\Property(property: 'answer', properties: [
            new OA\Property(property: 'lat', type: 'number', format: 'float'),
            new OA\Property(property: 'lng', type: 'number', format: 'float'),
            new OA\Property(property: 'year', type: 'integer'),
        ], type: 'object'),
        new OA\Property(property: 'memory', description: 'id null si le souvenir a été supprimé depuis', properties: [
            new OA\Property(property: 'id', type: 'integer', nullable: true),
            new OA\Property(property: 'title', type: 'string'),
        ], type: 'object'),
        new OA\Property(property: 'distanceKm', type: 'number', format: 'float', example: 412.7),
        new OA\Property(property: 'yearDiff', type: 'integer', example: 4),
        new OA\Property(property: 'locationScore', type: 'integer', description: '0 à 100 : 100·exp(−distance/50)', example: 76),
        new OA\Property(property: 'yearScore', type: 'integer', description: '0 à 100 : 100·exp(−écart/25)', example: 85),
        new OA\Property(property: 'score', type: 'integer', description: 'locationScore + yearScore (200 au plus)', example: 161),
    ],
    type: 'object'
)]
class QuizController extends AbstractController
{
    public function __construct(
        private readonly QuizGameService $game,
        private readonly QuizPresenter $presenter,
        private readonly QuizStats $stats,
        private readonly QuizClock $clock,
    ) {
    }

    /**
     * État du quiz du jour pour l'utilisateur connecté
     */
    #[Route('/api/secure/quiz/daily', methods: ['GET'])]
    #[OA\Response(
        response: 200,
        description: '`status` : not_started | in_progress | finished. `currentRound` (numéro et photo seulement) n\'est renseignée qu\'en cours ; `rounds` contient les manches déjà répondues avec leur correction. `streak` : série de jours consécutifs terminés (celle d\'hier reste valide tant qu\'aujourd\'hui n\'est pas joué).',
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'date', type: 'string', example: '2026-11-03'),
            new OA\Property(property: 'status', type: 'string', enum: ['not_started', 'in_progress', 'finished']),
            new OA\Property(property: 'totalRounds', type: 'integer', example: 5),
            new OA\Property(property: 'maxScore', type: 'integer', example: 1000),
            new OA\Property(property: 'score', type: 'integer', nullable: true, example: 7340),
            new OA\Property(property: 'currentRound', nullable: true, properties: [
                new OA\Property(property: 'position', type: 'integer', example: 2),
                new OA\Property(property: 'totalRounds', type: 'integer', example: 5),
                new OA\Property(property: 'pictureUrl', type: 'string', example: '/api/quiz/picture/12?exp=1793700000&sig=9f2c…'),
            ], type: 'object'),
            new OA\Property(property: 'rounds', type: 'array', items: new OA\Items(ref: '#/components/schemas/QuizRoundResult')),
            new OA\Property(property: 'streak', properties: [
                new OA\Property(property: 'current', type: 'integer', example: 3),
                new OA\Property(property: 'best', type: 'integer', example: 7),
            ], type: 'object'),
        ])
    )]
    #[OA\Response(response: 401, description: 'JWT absent ou invalide')]
    #[OA\Tag(name: 'quiz')]
    public function daily(): JsonResponse
    {
        $user = $this->currentUser();

        return $this->json($this->presenter->state($this->game->findToday($user), $this->clock->today(), $this->stats->streak($user)));
    }

    /**
     * Crée la partie du jour ou reprend celle qui existe (idempotent)
     */
    #[Route('/api/secure/quiz/daily/start', methods: ['POST'])]
    #[OA\Response(response: 201, description: 'Partie créée : même format que `GET /api/secure/quiz/daily` (`status` in_progress, `currentRound` = manche 1)')]
    #[OA\Response(response: 200, description: 'Partie déjà commencée ou terminée : même format, rien n\'est recréé')]
    #[OA\Response(response: 401, description: 'JWT absent ou invalide')]
    #[OA\Response(response: 503, description: 'Moins de 5 souvenirs éligibles : le quiz du jour ne peut pas être composé')]
    #[OA\Tag(name: 'quiz')]
    #[RequiresVerifiedEmail]
    public function start(): JsonResponse
    {
        $user = $this->currentUser();

        try {
            [$game, $created] = $this->game->start($user);
        } catch (QuizException $e) {
            return $this->error($e);
        }

        return $this->json(
            $this->presenter->state($game, $this->clock->today(), $this->stats->streak($user)),
            $created ? Response::HTTP_CREATED : Response::HTTP_OK,
        );
    }

    /**
     * Répond à la manche courante
     */
    #[Route('/api/secure/quiz/daily/answer', methods: ['POST'])]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(
        required: ['lat', 'lng', 'year'],
        properties: [
            new OA\Property(property: 'lat', type: 'number', format: 'float', minimum: -90, maximum: 90, example: 48.8566),
            new OA\Property(property: 'lng', type: 'number', format: 'float', minimum: -180, maximum: 180, example: 2.3522),
            new OA\Property(property: 'year', type: 'integer', description: 'Entre 1500 et l\'année en cours', example: 1950),
        ]
    ))]
    #[OA\Response(
        response: 200,
        description: 'Résultat de la manche (vérité, souvenir, détail du score), puis la manche suivante (`nextRound`, null à la fin) et, à la dernière manche, le récapitulatif `total` et la série `streak`.',
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'result', ref: '#/components/schemas/QuizRoundResult'),
            new OA\Property(property: 'gameScore', type: 'integer', example: 7340),
            new OA\Property(property: 'finished', type: 'boolean', example: false),
            new OA\Property(property: 'nextRound', nullable: true, properties: [
                new OA\Property(property: 'position', type: 'integer', example: 3),
                new OA\Property(property: 'totalRounds', type: 'integer', example: 5),
                new OA\Property(property: 'pictureUrl', type: 'string', example: '/api/quiz/picture/13?exp=1793700000&sig=4b7a…'),
            ], type: 'object'),
            new OA\Property(property: 'total', nullable: true, properties: [
                new OA\Property(property: 'score', type: 'integer', example: 31200),
                new OA\Property(property: 'maxScore', type: 'integer', example: 1000),
                new OA\Property(property: 'rounds', type: 'array', items: new OA\Items(ref: '#/components/schemas/QuizRoundResult')),
            ], type: 'object'),
            new OA\Property(property: 'streak', nullable: true, properties: [
                new OA\Property(property: 'current', type: 'integer', example: 4),
                new OA\Property(property: 'best', type: 'integer', example: 7),
            ], type: 'object'),
        ])
    )]
    #[OA\Response(response: 401, description: 'JWT absent ou invalide')]
    #[OA\Response(response: 404, description: 'Pas de partie aujourd\'hui')]
    #[OA\Response(response: 409, description: 'Partie terminée, pas de manche en cours, ou manche déjà répondue (double clic)')]
    #[OA\Response(response: 422, description: 'lat, lng ou year invalide')]
    #[OA\Tag(name: 'quiz')]
    #[RequiresVerifiedEmail]
    public function answer(Request $request, ApiValidationErrorResponder $responder): JsonResponse
    {
        $user = $this->currentUser();
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['error' => 'Corps JSON invalide'], Response::HTTP_BAD_REQUEST);
        }

        $errors = [];
        $lat = $this->number($payload['lat'] ?? null);
        $lng = $this->number($payload['lng'] ?? null);
        $year = $this->integer($payload['year'] ?? null);
        if ($lat === null || abs($lat) > 90) {
            $errors['answer.lat'][] = 'La latitude doit être un nombre compris entre -90 et 90.';
        }
        if ($lng === null || abs($lng) > 180) {
            $errors['answer.lng'][] = 'La longitude doit être un nombre compris entre -180 et 180.';
        }
        $maxYear = (int) $this->clock->today()->format('Y');
        if ($year === null || $year < Memory::MIN_YEAR || $year > $maxYear) {
            $errors['answer.year'][] = sprintf('L\'année doit être un entier compris entre %d et %d.', Memory::MIN_YEAR, $maxYear);
        }
        if ($errors) {
            return $responder->createResponse($errors);
        }

        try {
            ['round' => $round, 'game' => $game] = $this->game->answer($user, $lat, $lng, $year);
        } catch (QuizException $e) {
            return $this->error($e);
        }

        $finished = $game->isFinished();
        $next = $finished ? null : $game->getCurrentRound();

        return $this->json([
            'result' => $this->presenter->result($round),
            'gameScore' => $game->getTotalScore(),
            'finished' => $finished,
            'nextRound' => $next ? $this->presenter->question($next) : null,
            'total' => $finished ? $this->presenter->total($game) : null,
            'streak' => $finished ? $this->stats->streak($user) : null,
        ]);
    }

    /**
     * Photo d'une manche (URL signée fournie par `pictureUrl`, valable 1 heure, sans JWT pour qu'une balise <img> la charge)
     */
    #[Route('/api/quiz/picture/{id<\d+>}', methods: ['GET'])]
    #[OA\Parameter(name: 'id', in: 'path', required: true, description: 'ID de la manche (jamais celui du souvenir)', schema: new OA\Schema(type: 'integer'))]
    #[OA\Parameter(name: 'exp', in: 'query', required: true, description: 'Expiration (timestamp), signée', schema: new OA\Schema(type: 'integer'))]
    #[OA\Parameter(name: 'sig', in: 'query', required: true, description: 'Signature HMAC', schema: new OA\Schema(type: 'string'))]
    #[OA\Response(response: 200, description: 'Image (en-têtes de cache privés ; le nom du fichier n\'apparaît nulle part)')]
    #[OA\Response(response: 403, description: 'Lien invalide ou expiré : relire `GET /api/secure/quiz/daily` pour obtenir une URL fraîche')]
    #[OA\Response(response: 404, description: 'Manche ou fichier introuvable')]
    #[OA\Tag(name: 'quiz')]
    public function picture(int $id, Request $request, QuizPictures $pictures, EntityManagerInterface $em): Response
    {
        if (!$pictures->isValid($id, $request->query->getInt('exp'), (string) $request->query->get('sig'))) {
            return $this->json(['error' => 'Lien invalide ou expiré.'], Response::HTTP_FORBIDDEN);
        }
        $round = $em->find(QuizRound::class, $id);
        $path = $round ? $pictures->path($round) : null;
        if ($path === null) {
            return $this->json(['error' => 'Photo introuvable.'], Response::HTTP_NOT_FOUND);
        }

        $response = new BinaryFileResponse($path);
        $response->headers->set('Cache-Control', 'private, max-age=' . (QuizPictures::LIFETIME - 60));
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    /**
     * Statistiques personnelles
     */
    #[Route('/api/secure/quiz/me', methods: ['GET'])]
    #[OA\Response(
        response: 200,
        description: 'Statistiques sur les parties terminées (les parties abandonnées ne comptent pas)',
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'currentStreak', type: 'integer', example: 3),
            new OA\Property(property: 'bestStreak', type: 'integer', example: 7),
            new OA\Property(property: 'gamesPlayed', type: 'integer', example: 12),
            new OA\Property(property: 'bestScore', type: 'integer', example: 38250),
            new OA\Property(property: 'averageScore', type: 'number', format: 'float', example: 27431.5),
        ])
    )]
    #[OA\Response(response: 401, description: 'JWT absent ou invalide')]
    #[OA\Tag(name: 'quiz')]
    public function me(): JsonResponse
    {
        return $this->json($this->stats->summary($this->currentUser()));
    }

    /**
     * Classement du quiz (public ; avec un JWT, le rang de l'utilisateur connecté est ajouté dans `me`)
     */
    #[Route('/api/quiz/leaderboard', methods: ['GET'])]
    #[OA\Parameter(name: 'type', in: 'query', description: 'score : meilleur score de la période ; streak : meilleure série de jours consécutifs (period ignoré)', schema: new OA\Schema(type: 'string', enum: ['score', 'streak'], default: 'score'))]
    #[OA\Parameter(name: 'period', in: 'query', description: 'day : aujourd\'hui ; week : 7 jours glissants ; all : depuis toujours', schema: new OA\Schema(type: 'string', enum: ['day', 'week', 'all'], default: 'day'))]
    #[OA\Parameter(name: 'limit', in: 'query', description: 'Nombre de lignes (défaut 20, maximum 100)', schema: new OA\Schema(type: 'integer', default: 20, maximum: 100))]
    #[OA\Response(
        response: 200,
        description: 'Parties terminées uniquement. Joueurs réduits à `id` et `displayName`. Égalité = même rang. `me` : null si anonyme ou sans partie terminée dans la période.',
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'type', type: 'string', example: 'score'),
            new OA\Property(property: 'period', type: 'string', example: 'day'),
            new OA\Property(property: 'limit', type: 'integer', example: 20),
            new OA\Property(property: 'entries', type: 'array', items: new OA\Items(properties: [
                new OA\Property(property: 'rank', type: 'integer', example: 1),
                new OA\Property(property: 'user', properties: [
                    new OA\Property(property: 'id', type: 'integer', example: 7),
                    new OA\Property(property: 'displayName', type: 'string', example: 'Paul'),
                ], type: 'object'),
                new OA\Property(property: 'score', type: 'integer', description: 'type=score', example: 38250),
                new OA\Property(property: 'gamesPlayed', type: 'integer', description: 'type=score', example: 3),
                new OA\Property(property: 'streak', type: 'integer', description: 'type=streak : meilleure série', example: 9),
                new OA\Property(property: 'currentStreak', type: 'integer', description: 'type=streak', example: 2),
            ])),
            new OA\Property(property: 'me', type: 'object', nullable: true, example: ['rank' => 14, 'score' => 21000, 'gamesPlayed' => 2]),
        ])
    )]
    #[OA\Response(response: 422, description: 'type ou period inconnu')]
    #[OA\Tag(name: 'quiz')]
    public function leaderboard(Request $request, QuizLeaderboard $leaderboard, ApiValidationErrorResponder $responder): JsonResponse
    {
        $type = (string) $request->query->get('type', 'score');
        $period = (string) $request->query->get('period', 'day');
        $errors = [];
        if (!in_array($type, QuizLeaderboard::TYPES, true)) {
            $errors['query.type'][] = 'Le type doit valoir : ' . implode(', ', QuizLeaderboard::TYPES) . '.';
        }
        if (!in_array($period, QuizLeaderboard::PERIODS, true)) {
            $errors['query.period'][] = 'La période doit valoir : ' . implode(', ', QuizLeaderboard::PERIODS) . '.';
        }
        if ($errors) {
            return $responder->createResponse($errors);
        }

        $rawLimit = $request->query->get('limit');
        $limit = is_numeric($rawLimit) ? (int) $rawLimit : QuizLeaderboard::DEFAULT_LIMIT;
        $viewer = $this->getUser();

        return $this->json($leaderboard->build($type, $period, $limit, $viewer instanceof User ? $viewer : null));
    }

    private function currentUser(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }

    private function error(QuizException $e): JsonResponse
    {
        return $this->json(['error' => $e->getMessage()], $e->getStatusCode());
    }

    /** Nombre fini (jamais un booléen) ou null */
    private function number(mixed $value): ?float
    {
        if ((is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) && is_finite((float) $value)) {
            return (float) $value;
        }

        return null;
    }

    /** Entier (ou chaîne entière) ou null */
    private function integer(mixed $value): ?int
    {
        $number = $this->number($value);

        return $number !== null && $number == floor($number) && abs($number) < 1e9 ? (int) $number : null;
    }
}
