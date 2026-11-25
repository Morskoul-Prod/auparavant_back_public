<?php

namespace App\Controller\Back\Sort;

use App\Repository\MemoryRepository;
use App\Repository\PictureRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/back/sort/picture')]
class PictureSortController extends AbstractController
{
    /** Champs triables (liste blanche) : toute autre valeur retombe sur l'id. */
    private const ORDERABLE = ['id', 'imageName', 'position', 'type', 'status', 'createdAt', 'updatedAt', 'memory'];

    /**
     * Gestion du tri des photos
     *
     * @param Request $request
     * @param PictureRepository $pictureRepository
     * @param MemoryRepository $memoryRepository
     * @return Response
     */
    #[Route('/', name: 'app_sort_picture_by', methods: ['GET'])]
    public function sortBy(Request $request, PictureRepository $pictureRepository, MemoryRepository $memoryRepository): Response
    {
        $order = $request->query->all()['order'] ?? null;
        $order = is_string($order) && in_array($order, self::ORDERABLE, true) ? $order : 'id';
        $selectedDirection = $request->query->all()['selectedDirection'] ?? 'asc';

        $direction = in_array($selectedDirection, ['asc', 'desc'], true) ? $selectedDirection : 'asc';

        $sortResults = null;
        $pictures = null;

        if ($order === 'memory') {
            $sortResults = $pictureRepository->findByOrderAlphabeticalMemory($direction);
        }

        $pictures = $pictureRepository->findBy([], [$order => $direction]);

        return $this->render('back/picture/index.html.twig', [
            'pictures' => $pictures,
            'order' => $order,
            'sortResults' => $sortResults,
        ]);
    }
}
