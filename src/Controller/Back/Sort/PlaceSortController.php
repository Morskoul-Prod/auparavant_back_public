<?php

namespace App\Controller\Back\Sort;

use App\Repository\LocationRepository;
use App\Repository\PlaceRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/back/sort/place')]
class PlaceSortController extends AbstractController
{
    /** Champs triables (liste blanche) : toute autre valeur retombe sur l'id. */
    private const ORDERABLE = ['id', 'name', 'type', 'createdAt', 'updatedAt', 'location'];

    /**
     * Gestion du tri des lieux
     *
     * @param Request $request
     * @param LocationRepository $locationRepository
     * @param PlaceRepository $placeRepository
     * @return Response
     */
    #[Route('/', name: 'app_sort_place_by', methods: ['GET'])]
    public function sortBy(Request $request, LocationRepository $locationRepository, PlaceRepository $placeRepository): Response
    {
        $order = $request->query->all()['order'] ?? null;
        $order = is_string($order) && in_array($order, self::ORDERABLE, true) ? $order : 'id';
        $selectedDirection = $request->query->all()['selectedDirection'] ?? 'asc';

        $direction = in_array($selectedDirection, ['asc', 'desc'], true) ? $selectedDirection : 'asc';

        $sortResults = null;
        $places = null;

        if ($order === 'location') {
            $sortResults = $locationRepository->findByOrderAlphabeticalStreet($direction);
        }
        $places = $placeRepository->findBy([], [$order => $direction]);

        return $this->render('back/place/index.html.twig', [
            'places' => $places,
            'order' => $order,
            'sortResults' => $sortResults,
        ]);
    }
}
