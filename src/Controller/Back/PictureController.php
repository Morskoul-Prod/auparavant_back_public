<?php

namespace App\Controller\Back;

use App\Entity\Picture;
use App\Entity\User;
use App\Form\PictureType;
use App\Repository\PictureRepository;
use App\Service\ModerationNotifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/back/picture')]
class PictureController extends AbstractController
{
    /**
     * Affiche toutes les photos
     */
    #[Route('/', name: 'app_picture_index', methods: ['GET'])]
    public function index(PictureRepository $pictureRepository): Response
    {
        return $this->render('back/picture/index.html.twig', [
            'pictures' => $pictureRepository->findAllPicturesOrderedByMemoryId(),
        ]);
    }

    /**
     * Crée une photo avec un formulaire
     */
    #[Route('/new', name: 'app_picture_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        $picture = new Picture();
        $form = $this->createForm(PictureType::class, $picture);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // ✅ VichUploader gère automatiquement l'upload via imageFile

            $entityManager->persist($picture);
            $entityManager->flush();

            $this->addFlash('success', 'Image téléchargée avec succès');

            return $this->redirectToRoute('app_picture_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('back/picture/new.html.twig', [
            'picture' => $picture,
            'form' => $form,
        ]);
    }

    /**
     * Affiche une photo à partir de son id
     */
    #[Route('/{id}', name: 'app_picture_show', methods: ['GET'])]
    public function show(Picture $picture): Response
    {
        return $this->render('back/picture/show.html.twig', [
            'picture' => $picture,
        ]);
    }

    /**
     * Modifie une photo à partir de son id avec un formulaire
     */
    #[Route('/{id}/edit', name: 'app_picture_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Picture $picture, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(PictureType::class, $picture);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // ✅ VichUploader gère automatiquement :
            // - La suppression de l'ancien fichier (delete_on_update: true)
            // - L'upload du nouveau fichier

            $entityManager->flush();

            $this->addFlash('success', 'Image modifiée avec succès');

            return $this->redirectToRoute('app_picture_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('back/picture/edit.html.twig', [
            'picture' => $picture,
            'form' => $form,
        ]);
    }

    /**
     * Supprime une photo à partir de son id. Une photo rattachée à un souvenir peut l'être tant qu'il en reste
     * une visible ; celui qui l'avait proposée en est informé. L'appel AJAX du formulaire de souvenir reçoit du JSON.
     */
    #[Route('/{id}', name: 'app_picture_delete', methods: ['POST'])]
    public function delete(Request $request, Picture $picture, EntityManagerInterface $entityManager, ModerationNotifier $moderationNotifier): Response
    {
        $memory = $picture->getMemory();
        $memoryId = $memory?->getId();
        $ajax = $request->isXmlHttpRequest();

        if (!$this->isCsrfTokenValid('delete' . $picture->getId(), $request->request->get('_token'))) {
            return $ajax
                ? new JsonResponse(['error' => 'Jeton de sécurité invalide.'], Response::HTTP_FORBIDDEN)
                : $this->redirectToRoute('app_picture_index', [], Response::HTTP_SEE_OTHER);
        }

        // Un souvenir garde au moins une photo visible : pour la dernière, il faut supprimer le souvenir
        if ($memory !== null && $picture->isApproved() && count($memory->getApprovedPictures()) <= 1) {
            $error = 'Impossible de supprimer la dernière photo visible du souvenir : supprimez plutôt le souvenir.';
            if ($ajax) {
                return new JsonResponse(['error' => $error], Response::HTTP_CONFLICT);
            }
            $this->addFlash('error', $error);

            return $this->redirectToRoute('app_memory_edit', ['id' => $memoryId]);
        }

        // ✅ VichUploader supprime automatiquement le fichier (delete_on_remove: true)
        $user = $this->getUser();
        $moderationNotifier->pictureRemoved($picture, $user instanceof User ? $user : null, null, null, $memory);
        $memory?->removePicture($picture);
        $entityManager->remove($picture);
        $entityManager->flush();

        if ($ajax) {
            return new JsonResponse(['deleted' => true]);
        }

        $this->addFlash('success', 'Image supprimée avec succès');

        return $memoryId
            ? $this->redirectToRoute('app_memory_edit', ['id' => $memoryId])
            : $this->redirectToRoute('app_picture_index', [], Response::HTTP_SEE_OTHER);
    }
}
