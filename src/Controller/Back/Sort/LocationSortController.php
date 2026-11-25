<?php

namespace App\Controller\Back\Sort;

use App\Repository\LocationRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/back/sort/location')]
class LocationSortController extends AbstractController
{
    /** Champs triables (liste blanche) : toute autre valeur retombe sur l'id. */
    private const ORDERABLE = ['id', 'area', 'department', 'district', 'street', 'city', 'zipcode', 'createdAt', 'updatedAt'];

    /**
     * Gestion du tri des localisations
     *
     * @param Request $request
     * @param LocationRepository $locationRepository
     * @return Response
     */
    #[Route('/', name: 'app_sort_location_by', methods: ['GET'])]
    public function sortBy(Request $request, LocationRepository $locationRepository): Response
    {
        $order = $request->query->all()['order'] ?? null;
        $order = is_string($order) && in_array($order, self::ORDERABLE, true) ? $order : 'id';
        $selectedDirection = $request->query->all()['selectedDirection'] ?? 'asc';

        $direction = in_array($selectedDirection, ['asc', 'desc'], true) ? $selectedDirection : 'asc';

        $locations = $locationRepository->findBy([], [$order => $direction]);

        return $this->render('back/location/index.html.twig', [
            'locations' => $locations,
        ]);
    }
}
