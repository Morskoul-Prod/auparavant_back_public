<?php

namespace App\Controller\Api;

use App\Entity\Memory;
use App\Entity\Picture;
use App\Entity\User;
use App\Repository\CommentRepository;
use App\Repository\LocationRepository;
use App\Repository\MemoryLikeRepository;
use App\Repository\MemoryRepository;
use App\Repository\PlaceRepository;
use App\Security\RequiresVerifiedEmail;
use App\Security\Voter\MemoryVoter;
use App\Service\CurrentPictureAdder;
use App\Service\MemoryCreator;
use App\Service\MemoryLikeCounts;
use App\Service\MemoryProcessor;
use App\Service\MemoryRemover;
use App\Service\MemoryRequestReader;
use App\Service\MemoryUpdater;
use Doctrine\ORM\EntityManagerInterface;
use Nelmio\ApiDocBundle\Annotation\Model;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

class MemoryController extends AbstractController
{
    public function __construct(
        private readonly MemoryRepository $memoryRepository,
        private readonly MemoryProcessor $memoryProcessor,
        private readonly MemoryLikeCounts $memoryLikeCounts,
    ) {
    }

    /**
     * Affiche tous les souvenirs
     */
    #[Route('/api/memories', methods: ['GET'])]
    #[OA\Response(
        response: 200,
        description: 'Liste des souvenirs, le dernier créé en premier',
        content: new OA\JsonContent(
            type: 'array',
            items: new OA\Items(ref: new Model(type: Memory::class, groups: ['get_memory', 'get_location', 'get_place', 'get_user', 'get_picture', 'get_memory_likes']))
        )
    )]
    #[OA\Tag(name: 'memory')]
    public function index(): Response
    {
        $memories = $this->memoryLikeCounts->hydrate($this->memoryRepository->findAllNewestFirst());

        return $this->json($memories, 200, [], ['groups' => ['get_memory', 'get_location', 'get_place', 'get_user', 'get_picture', 'get_memory_likes']]);
    }

    /**
     * Route qui permet de récupérer l'ensemble des souvenirs associés avec une image avant/après
     */
    #[Route('/api/memorieswithtwopictures', methods: ['GET'])]
    #[OA\Response(
        response: 200,
        description: 'Returns the memory list with two pictures after/before',
        content: new OA\JsonContent(
            type: 'array',
            items: new OA\Items(ref: new Model(type: Memory::class, groups: ['get_memory', 'get_location', 'get_place', 'get_user', 'get_picture', 'get_memory_likes']))
        )
    )]
    #[OA\Tag(name: 'memory')]
    public function getAllMemoriesWithPicturesBeforeAfter(): Response
    {
        $memories = $this->memoryRepository->findAll();
        // Associer la compare_picture selon la date
        $processedMemories = $this->memoryProcessor->processMemories($memories);

        return $this->json($processedMemories, Response::HTTP_OK);
    }

    /**
     * Affiche un souvenir à partir de son id
     */
    #[Route('/api/memory/{id<\d+>}', methods: ['GET'])]
    #[OA\Response(
        response: 200,
        description: 'Returns a single memory',
        content: new OA\JsonContent(
            allOf: [
                new OA\Schema(ref: new Model(type: Memory::class, groups: ['get_memory', 'get_location', 'get_place', 'get_user', 'get_picture'])),
                new OA\Schema(properties: [
                    new OA\Property(property: 'likesCount', type: 'integer', description: 'Nombre de likes du souvenir', example: 4),
                    new OA\Property(property: 'commentsCount', type: 'integer', description: 'Nombre de commentaires publics (réponses comprises, hors masqués et supprimés)', example: 3),
                    new OA\Property(property: 'likedByMe', type: 'boolean', description: 'Vrai si l\'utilisateur connecté (JWT facultatif) l\'a aimé ; faux sans JWT', example: false),
                ], type: 'object'),
            ]
        )
    )]
    #[OA\Parameter(
        name: 'id',
        in: 'path',
        required: true,
        description: 'ID of the memory',
        schema: new OA\Schema(type: 'integer')
    )]
    #[OA\Tag(name: 'memory')]
    public function read(NormalizerInterface $normalizer, MemoryLikeRepository $memoryLikeRepository, CommentRepository $commentRepository, ?Memory $memory = null): Response
    {
        if (!$memory) {
            return $this->json(
                'Erreur : Souvenir inexistant',
                404
            );
        }

        $context = ['groups' => ['get_memory', 'get_location', 'get_place', 'get_user', 'get_picture']];
        $payload = $normalizer->normalize($memory, null, $context);

        // Route publique : photos approuvées + uniquement les propositions en attente et les refus non vus
        // de l'utilisateur connecté (JWT facultatif)
        $user = $this->getUser();
        $payload['pictures'] = array_map(function (Picture $picture) use ($normalizer, $context) {
            $data = $normalizer->normalize($picture, null, $context);
            // Le motif n'est ajouté que pour les refus, qui ne sont visibles que de leur auteur
            if ($picture->isRejected()) {
                $data['rejectionReason'] = $picture->getRejectionReason();
            }

            return $data;
        }, $memory->getPicturesVisibleBy($user instanceof User ? $user : null));

        $payload['likesCount'] = $memoryLikeRepository->countForTarget($memory);
        $payload['commentsCount'] = $commentRepository->countPublic($memory);
        $payload['likedByMe'] = $user instanceof User
            && $memoryLikeRepository->findOneBy(['memory' => $memory, 'user' => $user]) !== null;

        return $this->json($payload);
    }

    /**
     * Récupère les souvenirs d'un lieu triés par date (pour la fresque chronologique)
     */
    #[Route('/api/place/{id}/timeline', methods: ['GET'])]
    #[OA\Response(
        response: 200,
        description: 'Returns memories of a place ordered by date (timeline)',
        content: new OA\JsonContent(
            type: 'array',
            items: new OA\Items(ref: new Model(type: Memory::class, groups: ['get_memory', 'get_location', 'get_place', 'get_user', 'get_picture', 'get_memory_likes']))
        )
    )]
    #[OA\Parameter(
        name: 'id',
        in: 'path',
        required: true,
        description: 'ID of the place',
        schema: new OA\Schema(type: 'integer')
    )]
    #[OA\Tag(name: 'timeline')]
    public function getPlaceTimeline(int $id, PlaceRepository $placeRepository): Response
    {
        $place = $placeRepository->find($id);

        if (!$place) {
            return $this->json(['error' => 'Lieu introuvable'], Response::HTTP_NOT_FOUND);
        }

        $memories = $this->memoryLikeCounts->hydrate($this->memoryRepository->findByPlaceOrderedByDate($id));

        return $this->json([
            'place' => $place,
            'timeline' => $memories,
            'count' => count($memories),
        ], Response::HTTP_OK, [], ['groups' => ['get_memory', 'get_location', 'get_place', 'get_user', 'get_picture', 'get_memory_likes']]);
    }

    /**
     * Récupère les souvenirs d'une localisation triés par date (pour la fresque chronologique)
     */
    #[Route('/api/location/{id}/timeline', methods: ['GET'])]
    #[OA\Response(
        response: 200,
        description: 'Returns memories of a location ordered by date (timeline)',
        content: new OA\JsonContent(
            type: 'array',
            items: new OA\Items(ref: new Model(type: Memory::class, groups: ['get_memory', 'get_location', 'get_place', 'get_user', 'get_picture', 'get_memory_likes']))
        )
    )]
    #[OA\Parameter(
        name: 'id',
        in: 'path',
        required: true,
        description: 'ID of the location',
        schema: new OA\Schema(type: 'integer')
    )]
    #[OA\Tag(name: 'timeline')]
    public function getLocationTimeline(int $id, LocationRepository $locationRepository): Response
    {
        $location = $locationRepository->find($id);

        if (!$location) {
            return $this->json(['error' => 'Localisation introuvable'], Response::HTTP_NOT_FOUND);
        }

        $memories = $this->memoryLikeCounts->hydrate($this->memoryRepository->findByLocationOrderedByDate($id));

        return $this->json([
            'location' => $location,
            'timeline' => $memories,
            'count' => count($memories),
        ], Response::HTTP_OK, [], ['groups' => ['get_memory', 'get_location', 'get_place', 'get_user', 'get_picture', 'get_memory_likes']]);
    }

    /**
     * Souvenirs autour d'une position GPS (mode « balade », carte), du plus proche au plus éloigné
     */
    #[Route('/api/memories/nearby', methods: ['GET'])]
    #[OA\Response(
        response: 200,
        description: 'Souvenirs dans le rayon demandé, même format que GET /api/memories avec la clé `distance` (mètres) en plus',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'coordinates', properties: [
                    new OA\Property(property: 'lat', type: 'number'),
                    new OA\Property(property: 'lng', type: 'number'),
                ], type: 'object'),
                new OA\Property(property: 'radius_km', type: 'number'),
                new OA\Property(property: 'memories', type: 'array', items: new OA\Items(ref: new Model(type: Memory::class, groups: ['get_memory', 'get_location', 'get_place', 'get_user', 'get_picture', 'get_memory_likes']))),
                new OA\Property(property: 'count', type: 'integer'),
            ],
            type: 'object'
        )
    )]
    #[OA\Response(response: 400, description: 'Latitude ou longitude absente, non numérique ou hors limites')]
    #[OA\Parameter(name: 'lat', in: 'query', required: true, description: 'Latitude (-90 à 90)', schema: new OA\Schema(type: 'number', format: 'float'))]
    #[OA\Parameter(name: 'lng', in: 'query', required: true, description: 'Longitude (-180 à 180)', schema: new OA\Schema(type: 'number', format: 'float'))]
    #[OA\Parameter(name: 'radius', in: 'query', required: false, description: 'Rayon en kilomètres (défaut 1, de 0,05 à 50)', schema: new OA\Schema(type: 'number', format: 'float'))]
    #[OA\Parameter(name: 'limit', in: 'query', required: false, description: 'Nombre maximum de souvenirs (défaut 50, de 1 à 100)', schema: new OA\Schema(type: 'integer'))]
    #[OA\Tag(name: 'map')]
    public function getNearbyMemories(Request $request, NormalizerInterface $normalizer): Response
    {
        $lat = $request->query->get('lat');
        $lng = $request->query->get('lng');
        if (!is_numeric($lat) || !is_numeric($lng) || abs((float) $lat) > 90 || abs((float) $lng) > 180) {
            return $this->json(['error' => 'Latitude (-90 à 90) et longitude (-180 à 180) numériques requises'], Response::HTTP_BAD_REQUEST);
        }

        $radius = $request->query->get('radius', 1);
        $radius = is_numeric($radius) ? min(50.0, max(0.05, (float) $radius)) : 1.0;
        $limit = min(100, max(1, $request->query->getInt('limit', 50)));

        $found = $this->memoryRepository->findNearby((float) $lat, (float) $lng, $radius, $limit);
        $distances = [];
        foreach ($found as $item) {
            $distances[$item['memory']->getId()] = $item['distance'];
        }
        $memories = $this->memoryLikeCounts->hydrate(array_map(fn (array $item) => $item['memory'], $found));

        $context = ['groups' => ['get_memory', 'get_location', 'get_place', 'get_user', 'get_picture', 'get_memory_likes']];
        $payload = array_map(function (Memory $memory) use ($normalizer, $context, $distances) {
            return $normalizer->normalize($memory, null, $context) + ['distance' => (int) round($distances[$memory->getId()])];
        }, $memories);

        return $this->json([
            'coordinates' => ['lat' => (float) $lat, 'lng' => (float) $lng],
            'radius_km' => $radius,
            'memories' => $payload,
            'count' => count($payload),
        ]);
    }

    /**
     * Affiche les trois derniers souvenirs par date de création
     */
    #[Route('/api/memories/latest', methods: ['GET'])]
    #[OA\Response(
        response: 200,
        description: 'Returns the latest three memories',
        content: new OA\JsonContent(
            type: 'array',
            items: new OA\Items(ref: new Model(type: Memory::class, groups: ['get_memory', 'get_location', 'get_place', 'get_user', 'get_picture', 'get_memory_likes']))
        )
    )]
    #[OA\Tag(name: 'memory')]
    public function latest(): Response
    {
        $latestMemories = $this->memoryLikeCounts->hydrate($this->memoryRepository->findTheLatestOnes());

        if (!$latestMemories) {
            return $this->json(
                'Erreur : Données introuvables',
                404
            );
        }

        return $this->json(
            $latestMemories,
            200,
            [],
            ['groups' => ['get_memory', 'get_location', 'get_place', 'get_user', 'get_picture', 'get_memory_likes']]
        );
    }

    /**
     * Souvenir aléatoire avec sa photo ancienne et sa photo actuelle, pour le comparateur de la page d'accueil
     */
    #[Route('/api/memories/random', methods: ['GET'])]
    #[OA\Response(
        response: 200,
        description: 'Un souvenir tiré au hasard parmi ceux qui ont une photo avant et une photo actuelle approuvées',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'id', type: 'integer', example: 24),
                new OA\Property(property: 'title', type: 'string', example: 'La gare en 1950'),
                new OA\Property(property: 'year', type: 'integer', nullable: true, example: 1950),
                new OA\Property(property: 'before', properties: [
                    new OA\Property(property: 'imageUrl', type: 'string', example: '/uploads/memories/gare-avant.jpg'),
                    new OA\Property(property: 'copyrightHolder', type: 'string', nullable: true),
                ], type: 'object'),
                new OA\Property(property: 'after', properties: [
                    new OA\Property(property: 'imageUrl', type: 'string', example: '/uploads/memories/gare-apres.jpg'),
                    new OA\Property(property: 'copyrightHolder', type: 'string', nullable: true),
                ], type: 'object'),
            ],
            type: 'object'
        )
    )]
    #[OA\Response(response: 404, description: 'Aucun souvenir avec photo avant et photo actuelle')]
    #[OA\Tag(name: 'memory')]
    public function random(): Response
    {
        $memory = $this->memoryRepository->findRandomWithBeforeAfter();

        $before = null;
        $after = null;
        foreach ($memory?->getPictures() ?? [] as $picture) {
            if (!$picture->isApproved()) {
                continue;
            }
            if ($picture->isBefore()) {
                $before ??= $picture;
            } elseif ($picture->isAfter()) {
                $after ??= $picture;
            }
        }

        if (!$memory || !$before || !$after) {
            return $this->json(['error' => 'Aucun souvenir avec photo avant et photo actuelle'], Response::HTTP_NOT_FOUND);
        }

        $format = static fn (Picture $picture): array => [
            'imageUrl' => $picture->getImageUrl(),
            'copyrightHolder' => $picture->getCopyrightHolder(),
        ];

        return $this->json([
            'id' => $memory->getId(),
            'title' => $memory->getTitle(),
            'year' => $memory->getYear(),
            'before' => $format($before),
            'after' => $format($after),
        ]);
    }

    /**
     * Première méthode de création d'un souvenir
     * Crée un souvenir ainsi que le nom et le type du lieu, à partir d'une localisation choisie sur la carte
     */
    #[Route('/api/secure/create/memory-and-place', methods: ['POST'])]
    #[OA\RequestBody(
        description: 'Data with files (multipart/form-data)',
        content: new OA\MediaType(
            mediaType: 'multipart/form-data',
            schema: new OA\Schema(
                required: ['data'],
                properties: [
                    new OA\Property(
                        property: 'data',
                        type: 'string',
                        description: "Données JSON du souvenir, de la localisation et du lieu. `location.address_id` (string, 50 caractères max) est optionnel : il est renseigné sur la localisation existante seulement si elle n'en a pas encore. Date du souvenir : `memory.picture_date` (AAAA-MM-JJ) OU, pour une année seule, `memory.year` (entier de 1500 à l'année courante), exemple : {\"memory\":{\"title\":\"Le Panthéon\",\"content\":\"Photo de famille\",\"year\":1925}}. L'un des deux est obligatoire (400 sinon) ; si les deux sont fournis, la date prime. Une année seule renvoie picture_date: null.",
                        example: '{"location":{"id":1,"address_id":"75105_7255_00028"},"place":{"create_new_place":true,"name":"L\'Élysée","type":"maison"},"memory":{"title":"L\'Élysée en 1990","content":"Que de souvenirs","picture_date":"1990-02-08"}}'
                    ),
                    new OA\Property(
                        property: 'pictures[]',
                        type: 'array',
                        items: new OA\Items(type: 'string', format: 'binary'),
                        description: 'Image files'
                    ),
                    new OA\Property(
                        property: 'pictures_meta',
                        type: 'string',
                        description: 'Tableau JSON de métadonnées par photo (type, position, credit). type : before, after (photo actuelle du lieu, une seule par souvenir, approuvée d\'office pour l\'auteur) ou other ; 400 si le type est inconnu ou s\'il y a plus d\'une photo after, 409 si le souvenir en a déjà une.',
                        example: '[{"type":"other","position":0,"credit":"\u00a9 John Doe"},{"type":"other","position":1,"credit":"\u00a9 Jane Smith"}]'
                    ),
                ]
            )
        )
    )]
    #[OA\Response(
        response: 201,
        description: 'Returns the memory created',
        content: new OA\JsonContent(ref: new Model(type: Memory::class, groups: ['get_memory', 'get_location', 'get_place', 'get_user', 'get_picture']))
    )]
    #[OA\Tag(name: 'memory')]
    #[RequiresVerifiedEmail]
    public function createMemoryAndPlace(Request $request, MemoryRequestReader $reader, MemoryCreator $creator): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $memory = $creator->create($reader->readCreate($request), $request, $user);

        return $this->memoryResponse($memory, 'Souvenir créé', Response::HTTP_CREATED);
    }

    /**
     * Seconde méthode de création d'un souvenir
     * Crée un souvenir avec son lieu (nom, type) et sa localisation
     */
    #[Route('/api/secure/create/memory-and-location-and-place', methods: ['POST'])]
    #[OA\RequestBody(
        description: 'Data with files (multipart/form-data)',
        content: new OA\MediaType(
            mediaType: 'multipart/form-data',
            schema: new OA\Schema(
                required: ['data'],
                properties: [
                    new OA\Property(
                        property: 'data',
                        type: 'string',
                        description: "Données JSON du souvenir, de la localisation et du lieu. `location.address_id` (string, 50 caractères max) est optionnel : identifiant stable de l'API Adresse. Date du souvenir : `memory.picture_date` (AAAA-MM-JJ) OU, pour une année seule, `memory.year` (entier de 1500 à l'année courante), exemple : {\"memory\":{\"title\":\"Le Panthéon\",\"content\":\"Photo de famille\",\"year\":1925}}. L'un des deux est obligatoire (400 sinon) ; si les deux sont fournis, la date prime. Une année seule renvoie picture_date: null.",
                        example: '{"location":{"area":"Île-de-France","department":"Paris","district":"Quartier latin","street":"28 place du Panthéon","city":"Paris","zipcode":75005,"latitude":"48.84619800","longitude":"2.34610500","address_id":"75105_7255_00028"},"place":{"name":"Le Panthéon","type":"maison"},"memory":{"title":"Le Panthéon en 1990","content":"Que de souvenirs","picture_date":"1990-02-08"}}'
                    ),
                    new OA\Property(property: 'pictures[]', type: 'array', items: new OA\Items(type: 'string', format: 'binary')),
                    new OA\Property(property: 'pictures_meta', type: 'string', description: 'Tableau JSON de métadonnées par photo (type : before, after ou other ; une seule photo after par souvenir), voir /api/secure/create/memory-and-place'),
                ]
            )
        )
    )]
    #[OA\Response(
        response: 201,
        description: 'Returns the memory created',
        content: new OA\JsonContent(ref: new Model(type: Memory::class, groups: ['get_memory', 'get_location', 'get_place', 'get_user', 'get_picture']))
    )]
    #[OA\Tag(name: 'memory')]
    #[RequiresVerifiedEmail]
    public function createMemoryAndLocationAndPlace(Request $request, MemoryRequestReader $reader, MemoryCreator $creator): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $memory = $creator->createWithLocation($reader->readCreateWithLocation($request), $request, $user);

        return $this->memoryResponse($memory, 'Souvenir créé', Response::HTTP_CREATED);
    }

    /**
     * Modifie un souvenir à partir de son id
     */
    #[Route('/api/secure/update/memory-and-place/{id<\d+>}', methods: ['POST'])] // POST car on peut upload des fichiers
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\RequestBody(
        description: 'Data with optional files (multipart/form-data)',
        content: new OA\MediaType(
            mediaType: 'multipart/form-data',
            schema: new OA\Schema(
                required: ['data'],
                properties: [
                    new OA\Property(
                        property: 'data',
                        type: 'string',
                        description: "Données JSON du souvenir, de la localisation et du lieu. `location.address_id` (string, 50 caractères max) est optionnel : identifiant stable de l'API Adresse. Date du souvenir : `memory.picture_date` (AAAA-MM-JJ) OU, pour une année seule, `memory.year` (entier de 1500 à l'année courante), exemple : {\"memory\":{\"title\":\"Le Panthéon\",\"content\":\"Photo de famille\",\"year\":1925}}. L'un des deux est obligatoire (400 sinon) ; si les deux sont fournis, la date prime. Une année seule renvoie picture_date: null. En modification : envoyer uniquement `year` fait passer d'une date exacte à une année seule (picture_date remis à null) ; envoyer `picture_date` repasse en date exacte (year recalculé).",
                        example: '{"location":{"area":"Île-de-France","department":"Paris","district":"Quartier latin","street":"28 place du Panthéon","city":"Paris","zipcode":75005,"latitude":"48.84619800","longitude":"2.34610500","address_id":"75105_7255_00028"},"place":{"name":"Le Panthéon","type":"maison"},"memory":{"title":"Le Panthéon en 1990","content":"Que de souvenirs","picture_date":"1990-02-08"}}'
                    ),
                    new OA\Property(property: 'pictures[]', type: 'array', items: new OA\Items(type: 'string', format: 'binary'), description: 'New pictures to add'),
                    new OA\Property(property: 'pictures_meta', type: 'string', description: 'Métadonnées des nouvelles photos (type : before, after ou other ; une seule photo after approuvée par souvenir, cumul avec l\'existant : 409)'),
                    new OA\Property(property: 'before_picture_id', type: 'integer', description: 'Id d\'une photo approuvée du souvenir (hors photo actuelle) à utiliser comme photo « avant » du comparateur : elle devient « before » et les autres photos « avant » repassent en « other ». 422 (`errors.before_picture_id`) si elle n\'existe pas, est retirée dans la même requête ou est la photo actuelle.', example: 12),
                    new OA\Property(property: 'delete_pictures', type: 'string', description: 'Tableau JSON des ids de photos à retirer. L\'auteur ne retire que les photos qu\'il a envoyées (403 sinon) et ne peut pas retirer la dernière photo « avant », ni sa dernière photo « avant » ou « autre », sans en envoyer une autre dans la même modification (422, clé `delete_pictures`). Les fichiers sont supprimés du disque.', example: '[1,2,3]'),
                ]
            )
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Returns the updated memory',
        content: new OA\JsonContent(ref: new Model(type: Memory::class, groups: ['get_memory', 'get_location', 'get_place', 'get_user', 'get_picture']))
    )]
    #[OA\Response(response: 403, description: 'Utilisateur qui n\'est pas l\'auteur du souvenir, ou `delete_pictures` visant une photo envoyée par un autre utilisateur', content: new OA\JsonContent(example: ['error' => 'Vous ne pouvez retirer que les photos que vous avez envoyées.']))]
    #[OA\Response(response: 422, description: 'Validation (fichier non image, dernière photo « avant » ou « autre » retirée sans remplaçante : `errors.delete_pictures`)', content: new OA\JsonContent(example: ['error' => 'Données invalides', 'errors' => ['delete_pictures' => ['Un souvenir doit garder au moins une photo « avant » : ajoutez-en une autre avant de retirer celle-ci.']]]))]
    #[OA\Tag(name: 'memory')]
    public function updateMemoryAndPlace(int $id, Request $request, MemoryRequestReader $reader, MemoryUpdater $updater): Response
    {
        $memory = $this->memoryRepository->find($id);
        if (!$memory) {
            return $this->json(['error' => 'Memory not found'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->isGranted(MemoryVoter::EDIT, $memory)) {
            return $this->json(['error' => 'Unauthorized'], Response::HTTP_FORBIDDEN);
        }

        /** @var User $user */
        $user = $this->getUser();

        $updater->update($memory, $reader->readUpdate($request), $request, $user);

        return $this->memoryResponse($memory, 'Souvenir mis à jour', Response::HTTP_OK);
    }

    /**
     * Ajoute la photo actuelle (type « after ») d'un souvenir pour le slider avant/après.
     * Approuvée d'office pour l'auteur du souvenir quand il n'y a pas encore de photo actuelle, sinon en attente de validation
     * par un admin ou un modérateur. Si une photo actuelle approuvée existe déjà, la nouvelle (toujours en attente, même pour
     * l'auteur) la remplacera à sa validation : l'ancienne reste visible d'ici là (`replacesId`).
     */
    #[Route('/api/secure/memory/{id<\d+>}/current-picture', methods: ['POST'])]
    #[OA\Parameter(name: 'id', in: 'path', required: true, description: 'ID du souvenir', schema: new OA\Schema(type: 'integer'))]
    #[OA\RequestBody(
        description: 'Photo actuelle du lieu (multipart/form-data)',
        content: new OA\MediaType(
            mediaType: 'multipart/form-data',
            schema: new OA\Schema(
                required: ['picture'],
                properties: [
                    new OA\Property(property: 'picture', type: 'string', format: 'binary', description: 'Image JPEG, PNG, GIF ou WebP, 10 Mo maximum'),
                    new OA\Property(property: 'credit', type: 'string', description: 'Crédit photo (255 caractères max), optionnel', example: '© John Doe'),
                    new OA\Property(property: 'before_picture_id', type: 'integer', description: 'Optionnel, seulement si le souvenir n\'a pas de photo « avant » : id d\'une de ses photos approuvées à utiliser comme « avant » du comparateur. Appliqué tout de suite si la photo actuelle est approuvée d\'office (auteur, première photo actuelle), sinon à l\'approbation par le modérateur (`suggestedBeforeId` sur la proposition). 422 (`errors.before_picture_id`) sinon.', example: 12),
                ]
            )
        )
    )]
    #[OA\Response(
        response: 201,
        description: 'Photo créée. « approved » pour l\'auteur du souvenir s\'il n\'y a pas encore de photo actuelle ; « pending » (en attente de validation) pour les autres et pour tout remplacement d\'une photo actuelle existante, y compris celui de l\'auteur (`replacesId` = id de la photo à remplacer, qui reste visible jusqu\'à la validation)',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'picture', ref: new Model(type: Picture::class, groups: ['get_picture'])),
                new OA\Property(property: 'message', type: 'string'),
            ]
        )
    )]
    #[OA\Response(response: 400, description: 'Fichier « picture » manquant')]
    #[OA\Response(response: 401, description: 'JWT absent ou invalide')]
    #[OA\Response(response: 404, description: 'Souvenir introuvable')]
    #[OA\Response(response: 409, description: 'L\'utilisateur a déjà une proposition de photo actuelle (ajout ou remplacement) en attente sur ce souvenir')]
    #[OA\Response(response: 422, description: 'Fichier invalide (format, taille) ou crédit trop long')]
    #[OA\Tag(name: 'memory')]
    #[RequiresVerifiedEmail]
    public function addCurrentPicture(int $id, Request $request, CurrentPictureAdder $adder): Response
    {
        $memory = $this->memoryRepository->find($id);
        if (!$memory) {
            return $this->json(['error' => 'Souvenir introuvable'], Response::HTTP_NOT_FOUND);
        }

        /** @var User $user */
        $user = $this->getUser();

        $result = $adder->add($memory, $user, $this->isGranted(MemoryVoter::EDIT, $memory), $request);

        return $this->json(
            ['picture' => $result->picture, 'message' => $result->message],
            Response::HTTP_CREATED,
            [],
            ['groups' => ['get_picture']]
        );
    }

    /**
     * Supprime un souvenir à partir de son id (auteur uniquement ; la modération passe par le back-office)
     */
    #[Route('/api/secure/delete/memory/{id<\d+>}', methods: ['DELETE'])]
    #[OA\Response(response: 200, description: 'Souvenir supprimé')]
    #[OA\Response(response: 403, description: 'Pas l\'auteur du souvenir (un administrateur ou un modérateur le supprime depuis le back-office)')]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Tag(name: 'memory')]
    public function delete(Memory $memory, MemoryRemover $memoryRemover, EntityManagerInterface $entityManager): Response
    {
        if (!$this->isGranted(MemoryVoter::DELETE, $memory)) {
            return $this->json(['error' => 'Unauthorized'], Response::HTTP_FORBIDDEN);
        }

        /** @var \App\Entity\User $user */
        $user = $this->getUser();

        // Lieu et localisation sans autre souvenir sont supprimés dans le même flush ;
        // VichUploader supprime les fichiers grâce à orphanRemoval: true ; l'acteur est l'auteur : aucune notification
        $memoryRemover->remove($memory, $user);
        $entityManager->flush();

        return $this->json(['message' => 'Souvenir supprimé'], Response::HTTP_OK);
    }

    /** Réponse des routes de création et de modification : le souvenir et un message */
    private function memoryResponse(Memory $memory, string $message, int $status): Response
    {
        return $this->json(
            ['memory' => $memory, 'message' => $message],
            $status,
            [],
            ['groups' => ['get_memory', 'get_location', 'get_place', 'get_user', 'get_picture']]
        );
    }
}
