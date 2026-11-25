<?php

namespace App\Controller\Api;

use App\Entity\Place;
use App\Enum\PlaceType;
use App\Repository\MemoryRepository;
use App\Repository\PlaceRepository;
use Nelmio\ApiDocBundle\Annotation\Model;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Ce contrôleur regroupe les méthodes de lecture des lieux :
 * la liste, un lieu par son id, les types de lieu disponibles et les souvenirs d'un lieu.
 * Les lieux se créent, se modifient et se suppriment avec les souvenirs ou depuis le back-office.
 */
class PlaceController extends AbstractController
{
    /**
     * Affiche tous les lieux
     * @param PlaceRepository $placeRepository
     * @return Response
     */
    #[Route('/api/places', methods: ['GET'])]
    #[OA\Response(
        response: 200,
        description: 'Returns the place list',
        content: new OA\JsonContent(
            type: 'array',
            items: new OA\Items(ref: new Model(type: Place::class, groups: ['get_place'])),
            example: [
                [
                    'id' => 1,
                    'name' => 'Le Panthéon',
                    'type' => 'Mausolée',
                ],
                [
                    'id' => 2,
                    'name' => 'Tour Eiffel',
                    'type' => 'Tour autoportante',
                ],
            ]
        ))]
    #[OA\Tag(name: 'place')]
    public function index(PlaceRepository $placeRepository)
    {
        $places = $placeRepository->findAll();

        return $this->json($places, 200, [], ['groups' => ['get_place']]);
    }

    /**
     * Affiche un lieu à partir de son id
     * @param Place $place
     * @return Response
     */
    #[Route('/api/place/{id<\d+>}', methods: ['GET'])]
    #[OA\Response(
        response: 200,
        description: 'Returns a single place',
        content: new OA\JsonContent(
            type: 'array',
            items: new OA\Items(ref: new Model(type: Place::class, groups: ['get_place'])),
            example: [
                [
                    'id' => 1,
                    'name' => 'Le Panthéon',
                    'type' => 'Mausolée',
                ],
            ]
        ))]
    #[OA\Parameter(
        name: 'id',
        in: 'path',
        required: true,
        description: 'ID of the place',
        schema: new OA\Schema(type: 'integer')
    )]
    #[OA\Tag(name: 'place')]
    public function read(?Place $place = null)
    {
        if (!$place) {
            return $this->json(
                'Erreur : Endroit inexistant', 404
            );
        }

        return $this->json($place, 200, [], ['groups' => ['get_place']]
        );
    }

    /**
     * Récupère tous les types de lieu disponibles
     * @return JsonResponse
     */
    #[Route('/api/places/types', methods: ['GET'])]
    #[OA\Response(
        response: 200,
        description: 'Returns all available place types with their labels',
        content: new OA\JsonContent(
            type: 'object',
            example: [
                'types' => [
                    ['value' => 'maison', 'label' => 'Maison'],
                    ['value' => 'restaurant', 'label' => 'Restaurant'],
                    ['value' => 'parc', 'label' => 'Parc'],
                ],
                'grouped' => [
                    'Lieux de vie' => [
                        ['value' => 'maison', 'label' => 'Maison'],
                        ['value' => 'appartement', 'label' => 'Appartement'],
                    ],
                    'Loisirs & Culture' => [
                        ['value' => 'restaurant', 'label' => 'Restaurant'],
                        ['value' => 'cafe', 'label' => 'Café'],
                    ],
                ],
            ]
        )
    )]
    #[OA\Tag(name: 'place')]
    public function getPlaceTypes(): JsonResponse
    {
        // Liste complète des types
        $types = [];
        foreach (PlaceType::cases() as $type) {
            $types[] = [
                'value' => $type->value,
                'label' => $type->getLabel(),
            ];
        }

        // Types groupés par catégorie
        $grouped = [];
        foreach (PlaceType::getGroupedTypes() as $category => $placeTypes) {
            $grouped[$category] = array_map(
                fn (PlaceType $type) => [
                    'value' => $type->value,
                    'label' => $type->getLabel(),
                ],
                $placeTypes
            );
        }

        return $this->json([
            'types' => $types,
            'grouped' => $grouped,
        ]);
    }

    /**
     * Récupère les souvenirs d'un lieu, avec exclusion facultative
     * Utile pour afficher des souvenirs similaires ou du contenu associé
     *
     * @param int $placeId
     * @param Request $request
     * @param MemoryRepository $memoryRepository
     * @return JsonResponse
     */
    #[Route('/api/places/{placeId}/memories', name: 'api_place_memories', methods: ['GET'])]
    #[OA\Response(
        response: 200,
        description: 'Returns memories for a specific place',
        content: new OA\JsonContent(
            type: 'array',
            items: new OA\Items(ref: new Model(type: \App\Entity\Memory::class, groups: ['get_memory', 'get_location', 'get_place', 'get_user', 'get_picture', 'get_memory_likes']))
        )
    )]
    #[OA\Parameter(
        name: 'placeId',
        in: 'path',
        required: true,
        description: 'ID of the place',
        schema: new OA\Schema(type: 'integer')
    )]
    #[OA\Parameter(
        name: 'exclude',
        in: 'query',
        required: false,
        description: 'Memory ID to exclude from results',
        schema: new OA\Schema(type: 'integer')
    )]
    #[OA\Parameter(
        name: 'limit',
        in: 'query',
        required: false,
        description: 'Maximum number of results (default: 6)',
        schema: new OA\Schema(type: 'integer', default: 6)
    )]
    #[OA\Tag(name: 'place')]
    public function getMemoriesByPlace(
        int $placeId,
        Request $request,
        MemoryRepository $memoryRepository,
        \App\Service\MemoryLikeCounts $memoryLikeCounts,
    ): JsonResponse {
        $exclude = $request->query->getInt('exclude');
        $limit = $request->query->getInt('limit', 6);

        $memories = $memoryLikeCounts->hydrate($memoryRepository->findByPlaceExcluding(
            $placeId,
            $exclude ?: null,
            $limit
        ));

        return $this->json($memories, 200, [], ['groups' => ['get_memory', 'get_location', 'get_place', 'get_user', 'get_picture', 'get_memory_likes']]);
    }
}
