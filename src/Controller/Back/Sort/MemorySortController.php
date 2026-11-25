<?php

namespace App\Controller\Back\Sort;

use App\Repository\LocationRepository;
use App\Repository\MemoryRepository;
use App\Repository\PlaceRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/back/sort/memory')]
class MemorySortController extends AbstractController
{
    /** Champs triables (liste blanche) : toute autre valeur retombe sur l'id. */
    private const ORDERABLE = ['id', 'title', 'year', 'createdAt', 'updatedAt', 'location', 'place', 'user'];

    /**
     * Gestion du tri des souvenirs
     *
     * @param Request $request
     * @param MemoryRepository $memoryRepository
     * @param LocationRepository $locationRepository
     * @param PlaceRepository $placeRepository
     * @return Response
     */
    #[Route('/', name: 'app_sort_memory_by', methods: ['GET'])]
    public function sortBy(Request $request, MemoryRepository $memoryRepository, LocationRepository $locationRepository, PlaceRepository $placeRepository): Response
    {
        $order = $request->query->all()['order'] ?? null;
        $order = is_string($order) && in_array($order, self::ORDERABLE, true) ? $order : 'id';
        $selectedDirection = $request->query->all()['selectedDirection'] ?? 'asc';

        $direction = in_array($selectedDirection, ['asc', 'desc'], true) ? $selectedDirection : 'asc';

        $sortResults = null;
        $memories = null;

        if ($order === 'location') {
            $sortResults = $locationRepository->findByOrderAlphabeticalStreet($direction);
        }

        if ($order === 'place') {
            $sortResults = $placeRepository->findByOrderAlphabeticalPlace($direction);
        }

        $memories = $memoryRepository->findBy([], [$order => $direction]);

        return $this->render('back/memory/index.html.twig', [
            'memories' => $memories,
            'order' => $order,
            'sortResults' => $sortResults,
        ]);
    }
}
