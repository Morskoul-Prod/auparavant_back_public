<?php

namespace App\Controller\Back;

use App\Entity\Comment;
use App\Entity\User;
use App\Repository\CommentRepository;
use App\Service\CommentManager;
use App\Service\ModerationInput;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Commentaires masqués par la modération : les rétablir ou les supprimer définitivement.
 * Réservée aux modérateurs et aux administrateurs.
 */
#[Route('/back/comments/hidden')]
#[IsGranted('ROLE_MODERATOR')]
class CommentController extends AbstractController
{
    private const LIMIT = 20;

    /**
     * Liste les commentaires masqués, du masquage le plus récent au plus ancien
     */
    #[Route('/', name: 'app_comment_hidden_index', methods: ['GET'])]
    public function index(Request $request, CommentRepository $commentRepository): Response
    {
        $page = max(1, $request->query->getInt('page', 1));
        $total = $commentRepository->countHidden();

        return $this->render('back/comment/hidden.html.twig', [
            'comments' => $commentRepository->findHidden($page, self::LIMIT),
            'total' => $total,
            'page' => $page,
            'pages' => max(1, (int) ceil($total / self::LIMIT)),
        ]);
    }

    /**
     * Rétablit un commentaire masqué (son auteur en est informé)
     */
    #[Route('/{id<\d+>}/unhide', name: 'app_comment_unhide', methods: ['POST'])]
    public function unhide(Request $request, Comment $comment, CommentManager $commentManager, EntityManagerInterface $entityManager): Response
    {
        return $this->act($request, $comment, 'unhide', 'Commentaire rétabli', $entityManager, fn (ModerationInput $input) => $commentManager->unhide($comment, $this->currentUser(), $input->reason, $input->message));
    }

    /**
     * Supprime définitivement un commentaire masqué (règles habituelles de suppression)
     */
    #[Route('/{id<\d+>}/delete', name: 'app_comment_hidden_delete', methods: ['POST'])]
    public function delete(Request $request, Comment $comment, CommentManager $commentManager, EntityManagerInterface $entityManager): Response
    {
        return $this->act($request, $comment, 'delete-hidden', 'Commentaire supprimé', $entityManager, fn (ModerationInput $input) => $commentManager->delete($comment, $this->currentUser(), $input->reason, $input->message));
    }

    private function act(Request $request, Comment $comment, string $tokenPrefix, string $successMessage, EntityManagerInterface $entityManager, callable $action): Response
    {
        if (!$this->isCsrfTokenValid($tokenPrefix . $comment->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Jeton de sécurité invalide, veuillez réessayer.');
        } elseif (!$comment->isHidden() || $comment->isDeleted()) {
            $this->addFlash('warning', 'Ce commentaire n\'est plus masqué.');
        } elseif (($input = ModerationInput::fromRequest($request))->error !== null) {
            $this->addFlash('danger', $input->error);
        } else {
            $action($input);
            $entityManager->flush();
            $this->addFlash('success', $successMessage);
        }

        return $this->redirectToRoute('app_comment_hidden_index', [], Response::HTTP_SEE_OTHER);
    }

    private function currentUser(): ?User
    {
        $user = $this->getUser();

        return $user instanceof User ? $user : null;
    }
}
