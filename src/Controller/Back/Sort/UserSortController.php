<?php

namespace App\Controller\Back\Sort;

use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/back/sort/user')]
class UserSortController extends AbstractController
{
    /** Champs triables (liste blanche) : toute autre valeur retombe sur l'id. */
    private const ORDERABLE = ['id', 'pseudonym', 'email', 'createdAt', 'updatedAt'];

    /**
     * Gestion du tri des utilisateurs
     *
     * @param Request $request
     * @param UserRepository $userRepository
     * @return Response
     */
    #[Route('/', name: 'app_sort_user_by', methods: ['GET'])]
    public function sortBy(Request $request, UserRepository $userRepository): Response
    {
        $order = $request->query->all()['order'] ?? null;
        $order = is_string($order) && in_array($order, self::ORDERABLE, true) ? $order : 'id';
        $selectedDirection = $request->query->all()['selectedDirection'] ?? 'asc';

        $direction = in_array($selectedDirection, ['asc', 'desc'], true) ? $selectedDirection : 'asc';

        $users = $userRepository->findBy([], [$order => $direction]);

        return $this->render('back/user/index.html.twig', [
            'users' => $users,
        ]);
    }
}
