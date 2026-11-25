<?php

namespace App\Controller\Back;

use App\Entity\Comment;
use App\Entity\Memory;
use App\Entity\Picture;
use App\Entity\Report;
use App\Entity\User;
use App\Repository\ReportRepository;
use App\Service\CommentManager;
use App\Service\MemoryRemover;
use App\Service\ModerationInput;
use App\Service\ModerationNotifier;
use App\Service\ReportTargetResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * File de traitement des signalements de contenu. Réservée aux modérateurs et aux administrateurs.
 * Agir sur un contenu (masquer un commentaire, supprimer un commentaire, un souvenir ou une photo) clôt tous les signalements ouverts qui le visent ;
 * « ignorer » ne clôt que le signalement concerné.
 */
#[Route('/back/reports')]
#[IsGranted('ROLE_MODERATOR')]
class ReportController extends AbstractController
{
    /**
     * Liste les signalements ouverts avec un aperçu de la cible (droit d'auteur et contenu illégal en premier)
     */
    #[Route('/', name: 'app_report_index', methods: ['GET'])]
    public function index(ReportRepository $reportRepository, ReportTargetResolver $targetResolver): Response
    {
        $reports = $reportRepository->findOpen();

        return $this->render('back/report/index.html.twig', [
            'rows' => array_map(fn (Report $report) => [
                'report' => $report,
                // Null si la cible a été supprimée entre-temps
                'target' => $targetResolver->find($report->getTargetType(), $report->getTargetId()),
                'priority' => in_array($report->getReason(), ReportRepository::PRIORITY_REASONS, true),
            ], $reports),
        ]);
    }

    /**
     * Masque le commentaire signalé (il disparaît de l'API publique) et clôt ses signalements
     */
    #[Route('/{id<\d+>}/hide', name: 'app_report_hide', methods: ['POST'])]
    public function hide(Request $request, Report $report, ReportTargetResolver $targetResolver, CommentManager $commentManager, ModerationNotifier $moderationNotifier, ReportRepository $reportRepository, EntityManagerInterface $entityManager): Response
    {
        return $this->act($request, $report, 'Commentaire masqué', $targetResolver, $reportRepository, $entityManager, $moderationNotifier,
            function (object $target, ModerationInput $input) use ($commentManager) {
                if (!$target instanceof Comment) {
                    return 'Seul un commentaire peut être masqué.';
                }
                $commentManager->hide($target, $this->currentUser(), $input->reason, $input->message);

                return null;
            });
    }

    /**
     * Supprime le contenu signalé (commentaire, souvenir ou photo) et clôt ses signalements
     */
    #[Route('/{id<\d+>}/delete', name: 'app_report_delete', methods: ['POST'])]
    public function delete(Request $request, Report $report, ReportTargetResolver $targetResolver, CommentManager $commentManager, MemoryRemover $memoryRemover, ModerationNotifier $moderationNotifier, ReportRepository $reportRepository, EntityManagerInterface $entityManager): Response
    {
        return $this->act($request, $report, 'Contenu supprimé', $targetResolver, $reportRepository, $entityManager, $moderationNotifier,
            function (object $target, ModerationInput $input) use ($commentManager, $memoryRemover, $moderationNotifier) {
                if ($target instanceof Comment) {
                    $commentManager->delete($target, $this->currentUser(), $input->reason, $input->message);
                } elseif ($target instanceof Memory) {
                    $memoryRemover->remove($target, $this->currentUser(), $input->reason, $input->message);
                } elseif ($target instanceof Picture) {
                    $memory = $target->getMemory();
                    // Un souvenir garde au moins une photo visible : s'il n'en reste qu'une, il faut supprimer le souvenir
                    if ($target->isApproved() && count($memory->getApprovedPictures()) <= 1) {
                        return 'C\'est la dernière photo visible du souvenir : supprimez plutôt le souvenir, ou ignorez le signalement.';
                    }
                    // Celui qui l'avait proposée en est informé (sauf s'il la supprime lui-même)
                    $moderationNotifier->pictureRemoved($target, $this->currentUser(), $input->reason, $input->message);
                    $memory->removePicture($target);
                }

                return null;
            });
    }

    /**
     * Ignore le signalement : le contenu reste en ligne
     */
    #[Route('/{id<\d+>}/ignore', name: 'app_report_ignore', methods: ['POST'])]
    public function ignore(Request $request, Report $report, ModerationNotifier $moderationNotifier, ReportTargetResolver $targetResolver, EntityManagerInterface $entityManager): Response
    {
        if (!$this->isCsrfTokenValid('report' . $report->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Jeton de sécurité invalide, veuillez réessayer.');
        } elseif (!$report->isOpen()) {
            $this->addFlash('warning', 'Ce signalement a déjà été traité.');
        } else {
            $report->markHandled($this->currentUser());
            // Pas de notification si le contenu avait déjà disparu : personne n'a statué
            $target = $targetResolver->find($report->getTargetType(), $report->getTargetId());
            if ($target !== null && !($target instanceof Comment && $target->isDeleted())) {
                $moderationNotifier->reportReviewed($report);
            }
            $entityManager->flush();
            $this->addFlash('success', 'Signalement ignoré');
        }

        return $this->redirectToRoute('app_report_index', [], Response::HTTP_SEE_OTHER);
    }

    /**
     * @param callable(object, ModerationInput): ?string $action reçoit la cible encore présente et l'objet / le message saisis ; renvoie un message d'erreur si l'action est impossible
     */
    private function act(
        Request $request,
        Report $report,
        string $successMessage,
        ReportTargetResolver $targetResolver,
        ReportRepository $reportRepository,
        EntityManagerInterface $entityManager,
        ModerationNotifier $moderationNotifier,
        callable $action,
    ): Response {
        if (!$this->isCsrfTokenValid('report' . $report->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Jeton de sécurité invalide, veuillez réessayer.');
        } elseif (!$report->isOpen()) {
            $this->addFlash('warning', 'Ce signalement a déjà été traité.');
        } elseif (($input = ModerationInput::fromRequest($request, $report->getReason()->getLabel()))->error !== null) {
            $this->addFlash('danger', $input->error);
        } else {
            $target = $targetResolver->find($report->getTargetType(), $report->getTargetId());
            $targetGone = $target === null || ($target instanceof Comment && $target->isDeleted());
            if (!$targetGone) {
                if ($error = $action($target, $input)) {
                    $this->addFlash('danger', $error);

                    return $this->redirectToRoute('app_report_index', [], Response::HTTP_SEE_OTHER);
                }
            }
            // Cible déjà supprimée : il n'y a plus rien à faire, le signalement est simplement clos
            foreach ($reportRepository->findOpenByTarget($report->getTargetType(), $report->getTargetId()) as $sameTarget) {
                $sameTarget->markHandled($this->currentUser());
                // Sauf si la cible avait déjà disparu : le signalement est alors clos sans que personne ait statué
                if (!$targetGone) {
                    $moderationNotifier->reportReviewed($sameTarget);
                }
            }
            $entityManager->flush();
            $this->addFlash('success', $successMessage);
        }

        return $this->redirectToRoute('app_report_index', [], Response::HTTP_SEE_OTHER);
    }

    private function currentUser(): ?User
    {
        $user = $this->getUser();

        return $user instanceof User ? $user : null;
    }
}
