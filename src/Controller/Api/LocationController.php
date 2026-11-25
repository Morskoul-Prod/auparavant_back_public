<?php

namespace App\Controller\Api;

use App\Entity\Location;
use App\Repository\LocationRepository;
use App\Repository\MemoryRepository;
use Nelmio\ApiDocBundle\Annotation\Model;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Ce contrôleur regroupe les méthodes de lecture des localisations :
 * la liste, une localisation par son id et les souvenirs d'une localisation.
 * Les localisations se créent, se modifient et se suppriment avec les souvenirs ou depuis le back-office.
 */
class LocationController extends AbstractController
{
    /**
     * Affiche toutes les localisations
     *
     * @param LocationRepository $locationRepository
     * @return Response
     */
    #[Route('/api/locations', methods: ['GET'])]
    #[OA\Response(
        response: 200,
        description: 'Returns the location list',
        content: new OA\JsonContent(
            type: 'array',
            items: new OA\Items(ref: new Model(type: Location::class, groups: ['get_location'])),
            example: [
                [
                    'id' => 1,
                    'area' => 'Île-de-France',
                    'department' => 'Paris',
                    'district' => 'Quartier latin',
                    'street' => '28 place du Panthéon',
                    'city' => 'Paris',
                    'zipcode' => 75005,
                    'latitude' => '48.84619800',
                    'longitude' => '2.34610500',
                    'address_id' => '75105_7255_00028',
                ],
                [
                    'id' => 2,
                    'area' => 'Île-de-France',
                    'department' => 'Paris',
                    'district' => 'Gros-Caillou',
                    'street' => '5 avenue Anatole-France',
                    'city' => 'Paris',
                    'zipcode' => 75007,
                    'latitude' => '48.85829600',
                    'longitude' => '2.29447900',
                    'address_id' => null,
                ],
            ]
        ))]
    #[OA\Tag(name: 'location')]
    public function index(LocationRepository $locationRepository)
    {
        $locations = $locationRepository->findAll();

        return $this->json(
            $locations, 200, [], ['groups' => ['get_location']]);
    }

    /**
     * Affiche une localisation à partir de son id
     *
     * @param Location $location
     * @return Response
     */
    #[Route('/api/location/{id<\d+>}', methods: ['GET'])]
    #[OA\Parameter(
        name: 'id',
        in: 'path',
        required: true,
        description: 'ID of the location',
        schema: new OA\Schema(type: 'integer')
    )]
    #[OA\Response(
        response: 200,
        description: 'Returns a single location',
        content: new OA\JsonContent(
            type: 'array',
            items: new OA\Items(ref: new Model(type: Location::class, groups: ['get_location'])),
            example: [
                [
                    'id' => 1,
                    'area' => 'Île-de-France',
                    'department' => 'Paris',
                    'district' => 'Quartier latin',
                    'street' => '28 place du Panthéon',
                    'city' => 'Paris',
                    'zipcode' => 75005,
                    'latitude' => '48.84619800',
                    'longitude' => '2.34610500',
                    'address_id' => '75105_7255_00028',
                ],
            ]
        ))]
    #[OA\Tag(name: 'location')]
    public function read(?Location $location = null)
    {
        if (!$location) {
            return $this->json(
                'Erreur : Localité inexistante', 404
            );
        }

        return $this->json($location, 200, [], ['groups' => ['get_location']]
        );
    }

    /**
     * Récupère les souvenirs d'une localisation, avec exclusion facultative
     * Utile pour afficher des souvenirs similaires ou du contenu associé
     *
     * @param int $locationId
     * @param Request $request
     * @param MemoryRepository $memoryRepository
     * @return JsonResponse
     */
    #[Route('/api/locations/{locationId}/memories', name: 'api_location_memories', methods: ['GET'])]
    #[OA\Response(
        response: 200,
        description: 'Returns memories for a specific location',
        content: new OA\JsonContent(
            type: 'array',
            items: new OA\Items(ref: new Model(type: \App\Entity\Memory::class, groups: ['get_memory', 'get_location', 'get_place', 'get_user', 'get_picture', 'get_memory_likes']))
        )
    )]
    #[OA\Parameter(
        name: 'locationId',
        in: 'path',
        required: true,
        description: 'ID of the location',
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
    #[OA\Tag(name: 'location')]
    public function getMemoriesByLocation(
        int $locationId,
        Request $request,
        MemoryRepository $memoryRepository,
        \App\Service\MemoryLikeCounts $memoryLikeCounts,
    ): JsonResponse {
        $exclude = $request->query->getInt('exclude');
        $limit = $request->query->getInt('limit', 6);

        $memories = $memoryLikeCounts->hydrate($memoryRepository->findByLocationExcluding(
            $locationId,
            $exclude ?: null,
            $limit
        ));

        return $this->json($memories, 200, [], ['groups' => ['get_memory', 'get_location', 'get_place', 'get_user', 'get_picture', 'get_memory_likes']]);
    }
}
