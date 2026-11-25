<?php

namespace App\Controller\Back;

use App\Entity\Place;
use App\Form\PlaceFormType;
use App\Repository\PlaceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/back/place')]
class PlaceController extends AbstractController
{
    /**
     * Affiche tous les lieux
     *
     * @param PlaceRepository $placeRepository
     * @return Response
     */
    #[Route('/', name: 'app_place_index', methods: ['GET'])]
    public function index(PlaceRepository $placeRepository): Response
    {
        return $this->render('back/place/index.html.twig', [
            'places' => $placeRepository->findAll(),
        ]);
    }

    /**
     * Crée un lieu avec un formulaire
     *
     * @param Request $request
     * @param EntityManagerInterface $entityManager
     * @return Response
     */
    #[Route('/new', name: 'app_place_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        $place = new Place();
        $form = $this->createForm(PlaceFormType::class, $place);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($place);
            $entityManager->flush();

            $this->addFlash('success', 'Lieu ajouté avec succès');

            return $this->redirectToRoute('app_place_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('back/place/new.html.twig', [
            'place' => $place,
            'form' => $form,
        ]);
    }

    /**
     * Affiche un lieu à partir de son id
     *
     * @param int $id
     * @param PlaceRepository $placeRepository
     * @return Response
     */
    #[Route('/{id}', name: 'app_place_show', methods: ['GET'])]
    public function show(int $id, PlaceRepository $placeRepository): Response
    {
        $place = $placeRepository->findOneWithMemories($id);

        if (!$place) {
            throw $this->createNotFoundException('Place non trouvée');
        }

        return $this->render('back/place/show.html.twig', [
            'place' => $place,
        ]);
    }

    /**
     * Modifie un lieu à partir de son id avec un formulaire
     * @param int $id
     * @param Request $request
     * @param PlaceRepository $placeRepository
     * @param EntityManagerInterface $entityManager
     * @return Response
     */
    #[Route('/{id}/edit', name: 'app_place_edit', methods: ['GET', 'POST'])]
    public function edit(int $id, Request $request, PlaceRepository $placeRepository, EntityManagerInterface $entityManager): Response
    {
        $place = $placeRepository->findOneWithMemories($id);

        $form = $this->createForm(PlaceFormType::class, $place);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();

            $this->addFlash('success', 'Lieu modifié avec succès');

            return $this->redirectToRoute('app_place_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('back/place/edit.html.twig', [
            'place' => $place,
            'form' => $form,
        ]);
    }

    /**
     * Supprime un lieu à partir de son id
     * @param Request $request
     * @param Place $place
     * @param EntityManagerInterface $entityManager
     * @return Response
     */
    #[Route('/{id}', name: 'app_place_delete', methods: ['POST'])]
    public function delete(Request $request, Place $place, EntityManagerInterface $entityManager): Response
    {
        if ($this->isCsrfTokenValid('delete' . $place->getId(), $request->request->get('_token'))) {
            if ($place->getMemories()->isEmpty()) {
                $entityManager->remove($place);
                $entityManager->flush();

                $this->addFlash('success', 'Lieu supprimé avec succès');

                return $this->redirectToRoute('app_place_index', [], Response::HTTP_SEE_OTHER);
            }
            $this->addFlash('danger', 'Impossible de supprimer ce lieu car il est lié à des souvenirs');

            return $this->redirect($request->headers->get('referer') ?? $this->generateUrl('app_place_index'));
        }

        return $this->redirectToRoute('app_place_index', [], Response::HTTP_SEE_OTHER);
    }
}
