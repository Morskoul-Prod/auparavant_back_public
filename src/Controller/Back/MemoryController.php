<?php

namespace App\Controller\Back;

use App\Entity\Memory;
use App\Entity\User;
use App\Form\MemoryType;
use App\Repository\MemoryRepository;
use App\Service\MemoryRemover;
use App\Service\ModerationInput;
use App\Service\ModerationNotifier;
use App\Service\OrphanLocationCleaner;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/back/memory')]
class MemoryController extends AbstractController
{
    #[Route('/', name: 'app_memory_index', methods: ['GET'])]
    public function index(MemoryRepository $memoryRepository): Response
    {
        return $this->render('back/memory/index.html.twig', [
            'memories' => $memoryRepository->findAll(),
        ]);
    }

    #[Route('/new', name: 'app_memory_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        $memory = new Memory();
        $form = $this->createForm(MemoryType::class, $memory);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // ✅ Grâce à by_reference=false, addPicture() est appelé automatiquement
            // et setMemory() est déjà fait dans addPicture()

            $entityManager->persist($memory);
            $entityManager->flush();

            $this->addFlash('success', 'Souvenir créé avec succès');

            return $this->redirectToRoute(
                'app_memory_show',
                ['id' => $memory->getId()],
                Response::HTTP_SEE_OTHER
            );
        }

        return $this->render('back/memory/new.html.twig', [
            'memory' => $memory,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_memory_show', methods: ['GET'])]
    public function show(Memory $memory): Response
    {
        $firstPicture = $memory->getPictures()->first();

        return $this->render('back/memory/show.html.twig', [
            'memory' => $memory,
            'firstPicture' => $firstPicture,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_memory_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Memory $memory, EntityManagerInterface $em, OrphanLocationCleaner $orphanLocationCleaner, ModerationNotifier $moderationNotifier): Response
    {
        // Photos présentes avant l'envoi du formulaire : celles qui disparaissent sont des suppressions
        $picturesBefore = $memory->getPictures()->toArray();

        // Le formulaire peut déplacer le souvenir : on garde l'ancien rattachement pour le nettoyer
        $oldLocation = $memory->getLocation();
        $oldPlace = $memory->getPlace();

        $form = $this->createForm(MemoryType::class, $memory);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // ✅ VichUploader gère automatiquement l'upload
            // ✅ by_reference=false gère addPicture()/removePicture()

            // Même transaction : le déplacement, puis la suppression de l'ancienne Location/Place devenue vide
            $removedPictures = array_filter($picturesBefore, fn ($picture) => !$memory->getPictures()->contains($picture));
            $moderator = $this->getUser();
            $em->wrapInTransaction(function () use ($em, $orphanLocationCleaner, $moderationNotifier, $memory, $oldLocation, $oldPlace, $removedPictures, $moderator) {
                // Celui qui avait proposé une photo retirée en est informé (sauf s'il la retire lui-même)
                foreach ($removedPictures as $picture) {
                    $moderationNotifier->pictureRemoved($picture, $moderator instanceof User ? $moderator : null, null, null, $memory);
                }
                $em->flush();
                $orphanLocationCleaner->cleanAfterMove($memory, $oldLocation, $oldPlace);
                $em->flush();
            });

            $this->addFlash('success', 'Souvenir modifié avec succès');

            return $this->redirectToRoute('app_memory_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('back/memory/edit.html.twig', [
            'memory' => $memory,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_memory_delete', methods: ['POST'])]
    public function delete(Request $request, Memory $memory, EntityManagerInterface $entityManager, MemoryRemover $memoryRemover): Response
    {
        if ($this->isCsrfTokenValid('delete' . $memory->getId(), $request->request->get('_token'))) {
            $input = ModerationInput::fromRequest($request);
            if ($input->error !== null) {
                $this->addFlash('danger', $input->error);

                return $this->redirectToRoute('app_memory_show', ['id' => $memory->getId()], Response::HTTP_SEE_OTHER);
            }

            // orphanRemoval supprime les photos (VichUploader leurs fichiers) ; l'auteur est prévenu si ce n'est pas lui
            $user = $this->getUser();
            $memoryRemover->remove($memory, $user instanceof User ? $user : null, $input->reason, $input->message);
            $entityManager->flush();

            $this->addFlash('success', 'Souvenir supprimé avec succès');
        }

        return $this->redirectToRoute('app_memory_index', [], Response::HTTP_SEE_OTHER);
    }
}
