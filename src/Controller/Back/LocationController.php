<?php

namespace App\Controller\Back;

use App\Entity\Location;
use App\Form\LocationType;
use App\Repository\LocationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/back/location')]
class LocationController extends AbstractController
{
    /**
     * Affiche toutes les localisations
     *
     * @param LocationRepository $locationRepository
     * @return Response
     */
    #[Route('/', name: 'app_location_index', methods: ['GET'])]
    public function index(LocationRepository $locationRepository): Response
    {
        return $this->render('back/location/index.html.twig', [
            'locations' => $locationRepository->findAll(),
        ]);
    }

    /**
     * Crée une localisation avec un formulaire
     *
     * @param Request $request
     * @param EntityManagerInterface $entityManager
     * @return Response
     */
    #[Route('/new', name: 'app_location_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        $location = new Location();
        $form = $this->createForm(LocationType::class, $location);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($location);
            $entityManager->flush();

            $this->addFlash('success', 'Localisation ajoutée avec succès');

            return $this->redirectToRoute('app_location_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('back/location/new.html.twig', [
            'location' => $location,
            'form' => $form,
        ]);
    }

    /**
     * Affiche une localisation à partir de son id
     *
     * @param int $id
     * @return Response
     */
    #[Route('/{id}', name: 'app_location_show', methods: ['GET'])]
    public function show(int $id, LocationRepository $locationRepository): Response
    {
        $location = $locationRepository->findOneWithMemories($id);

        if (!$location) {
            throw $this->createNotFoundException('Localisation non trouvée not found');
        }

        return $this->render('back/location/show.html.twig', [
            'location' => $location,
        ]);
    }

    /**
     * Modifie une localisation à partir de son id avec un formulaire
     * @param int $id
     * @param Request $request
     * @param LocationRepository $locationRepository
     * @param EntityManagerInterface $entityManager
     * @return Response
     */
    #[Route('/{id}/edit', name: 'app_location_edit', methods: ['GET', 'POST'])]
    public function edit(int $id, Request $request, LocationRepository $locationRepository, EntityManagerInterface $entityManager): Response
    {
        $location = $locationRepository->findOneWithMemories($id);

        $form = $this->createForm(LocationType::class, $location);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();

            $this->addFlash('success', 'Localisation modifiée avec succès');

            return $this->redirectToRoute('app_location_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('back/location/edit.html.twig', [
            'location' => $location,
            'form' => $form,
        ]);
    }

    /**
     * Supprime une localisation à partir de son id
     * @param Request $request
     * @param Location $location
     * @param EntityManagerInterface $entityManager
     * @return Response
     */
    #[Route('/{id}', name: 'app_location_delete', methods: ['POST'])]
    public function delete(Request $request, Location $location, EntityManagerInterface $entityManager): Response
    {
        if ($this->isCsrfTokenValid('delete' . $location->getId(), $request->request->get('_token'))) {
            if ($location->getMemories()->isEmpty()) {
                $entityManager->remove($location);
                $entityManager->flush();
                $this->addFlash('success', 'Localisation supprimée avec succès');

                return $this->redirectToRoute('app_location_index', [], Response::HTTP_SEE_OTHER);
            }
        }
        $this->addFlash('danger', 'Impossible de supprimer cette localisation car elle est liée à des souvenirs');

        return $this->redirect($request->headers->get('referer'));
    }
}
