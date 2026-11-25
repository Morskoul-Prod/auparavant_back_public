<?php

namespace App\Controller\Back;

use App\Entity\User;
use App\Form\UserType;
use App\Repository\UserRepository;
use App\Service\OrphanLocationCleaner;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/back/user')]
class UserController extends AbstractController
{
    /**
     * Affiche tous les utilisateurs
     *
     * @param UserRepository $userRepository
     * @return Response
     */
    #[Route('/', name: 'app_user_index', methods: ['GET'])]
    public function index(UserRepository $userRepository): Response
    {
        return $this->render('back/user/index.html.twig', [
            'users' => $userRepository->findAll(),
        ]);
    }

    /**
     * Crée un utilisateur avec un formulaire
     *
     * @param Request $request
     * @param EntityManagerInterface $entityManager
     * @return Response
     */
    #[Route('/new', name: 'app_user_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager, UserPasswordHasherInterface $hasher): Response
    {
        $user = new User();
        $form = $this->createForm(UserType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Le champ du formulaire porte le mot de passe en clair : on ne stocke que son hash
            $user->setPassword($hasher->hashPassword($user, $user->getPassword()));
            // Compte créé par un administrateur : pas de lien de confirmation à envoyer
            $user->setEmailVerifiedAt(new \DateTimeImmutable());
            $entityManager->persist($user);
            $entityManager->flush();

            $this->addFlash('success', 'Utilisateur créé avec succès');

            return $this->redirectToRoute('app_user_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('back/user/new.html.twig', [
            'user' => $user,
            'form' => $form,
        ]);
    }

    /**
     * Affiche un utilisateur à partir de son id
     *
     * @param User $user
     * @return Response
     */
    #[Route('/{id}', name: 'app_user_show', methods: ['GET'])]
    public function show(User $user): Response
    {
        return $this->render('back/user/show.html.twig', [
            'user' => $user,
        ]);
    }

    /**
     * Modifie un utilisateur à partir de son id avec un formulaire
     * @param Request $request
     * @param User $user
     * @param EntityManagerInterface $entityManager
     * @return Response
     */
    #[Route('/{id}/edit', name: 'app_user_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, User $user, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(UserType::class, $user, ['is_edit' => true]);
        $form->handleRequest($request);

        // Un administrateur ne peut pas se retirer lui-même le rôle d'administrateur : il perdrait l'accès
        // au back-office en cours de session (et il pourrait n'y avoir plus aucun administrateur)
        $current = $this->getUser();
        if ($form->isSubmitted() && $current instanceof User && $user->getId() === $current->getId() && !in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            $form->get('roles')->addError(new FormError('Vous ne pouvez pas vous retirer le rôle d\'administrateur.'));
        }

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();

            $this->addFlash('success', 'Utilisateur modifié avec succès');

            return $this->redirectToRoute('app_user_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('back/user/edit.html.twig', [
            'user' => $user,
            'form' => $form->createView(),
        ]);
    }

    /**
     * Supprime un utilisateur à partir de son id
     * @param Request $request
     * @param User $user
     * @param EntityManagerInterface $entityManager
     * @return Response
     */
    #[Route('/{id}', name: 'app_user_delete', methods: ['POST'])]
    public function delete(Request $request, User $user, EntityManagerInterface $entityManager, OrphanLocationCleaner $orphanLocationCleaner, \App\Service\LikeNotifier $likeNotifier, UserRepository $userRepository): Response
    {
        // Le seul administrateur ne peut pas être supprimé (sinon plus personne ne gère le back-office)
        if ($userRepository->isLastAdmin($user)) {
            $this->addFlash('danger', 'Impossible de supprimer le seul administrateur : donnez d\'abord ce rôle à un autre compte.');

            return $this->redirectToRoute('app_user_index', [], Response::HTTP_SEE_OTHER);
        }

        if ($this->isCsrfTokenValid('delete' . $user->getId(), $request->request->get('_token'))) {
            $orphanLocationCleaner->cleanAfterRemoval($user->getMemories()->toArray());
            $likeNotifier->forgetLiker($user);
            $entityManager->remove($user);
            $entityManager->flush();

            $this->addFlash('success', 'Utilisateur supprimé avec succès');
        }

        return $this->redirectToRoute('app_user_index', [], Response::HTTP_SEE_OTHER);
    }
}
