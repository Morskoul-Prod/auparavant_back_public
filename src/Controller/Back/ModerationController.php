<?php

namespace App\Controller\Back;

use App\Entity\Picture;
use App\Entity\User;
use App\Enum\PictureStatus;
use App\Enum\RejectionReason;
use App\Repository\PictureRepository;
use App\Service\BeforePictureChooser;
use App\Service\ModerationNotifier;
use App\Service\PictureFileRemover;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Modération des photos proposées par les utilisateurs (photos actuelles « after »).
 * Réservée aux modérateurs et aux administrateurs.
 */
#[Route('/back/moderation/pictures')]
#[IsGranted('ROLE_MODERATOR')]
class ModerationController extends AbstractController
{
    /**
     * Liste les photos en attente de validation
     */
    #[Route('/', name: 'app_moderation_index', methods: ['GET'])]
    public function index(PictureRepository $pictureRepository): Response
    {
        return $this->render('back/moderation/index.html.twig', [
            'pictures' => $pictureRepository->findPending(),
            'rejectionReasons' => RejectionReason::cases(),
        ]);
    }

    /**
     * Nombre de photos en attente, interrogé en polling par la pastille de la barre latérale
     */
    #[Route('/count', name: 'app_moderation_count', methods: ['GET'])]
    public function count(PictureRepository $pictureRepository): JsonResponse
    {
        return $this->json(['count' => $pictureRepository->countPending()]);
    }

    /**
     * Approuve une photo en attente
     */
    #[Route('/{id<\d+>}/approve', name: 'app_moderation_approve', methods: ['POST'])]
    public function approve(Request $request, Picture $picture, EntityManagerInterface $entityManager, ModerationNotifier $moderationNotifier, BeforePictureChooser $beforePictureChooser): Response
    {
        if (!$this->isCsrfTokenValid('approve' . $picture->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Jeton de sécurité invalide, veuillez réessayer.');

            return $this->redirectToRoute('app_moderation_index', [], Response::HTTP_SEE_OTHER);
        }

        if ($picture->getStatus() !== PictureStatus::PENDING) {
            $this->addFlash('warning', 'Cette photo n\'est plus en attente de validation.');

            return $this->redirectToRoute('app_moderation_index', [], Response::HTTP_SEE_OTHER);
        }

        // Un souvenir n'a qu'une seule photo actuelle approuvée : seule la photo que ce remplaçant vise peut être en place
        $existing = $picture->isAfter() ? $picture->getMemory()?->getApprovedAfterPicture() : null;
        if ($existing !== null && $existing !== $picture->getReplaces()) {
            $this->addFlash('danger', 'Ce souvenir possède déjà une photo actuelle approuvée : rejetez cette proposition ou supprimez d\'abord l\'existante.');

            return $this->redirectToRoute('app_moderation_index', [], Response::HTTP_SEE_OTHER);
        }

        // Remplacement : l'ancienne photo actuelle est supprimée avec son fichier (VichUploader), sans historique
        if ($existing !== null) {
            $moderationNotifier->currentPictureReplaced($picture);
            $picture->setReplaces(null);
            $entityManager->remove($existing);
        }

        $picture->setStatus(PictureStatus::APPROVED);
        $picture->setModeratedAt(new \DateTimeImmutable());
        $moderator = $this->getUser();
        $moderationNotifier->pictureApproved($picture, $moderator instanceof User ? $moderator : null);
        // La photo « avant » suggérée par le proposant, si le souvenir n'en a toujours pas
        if ($picture->isAfter()) {
            $beforePictureChooser->applySuggestion($picture);
        }
        $entityManager->flush();

        $this->addFlash('success', 'Photo approuvée');

        return $this->redirectToRoute('app_moderation_index', [], Response::HTTP_SEE_OTHER);
    }

    /**
     * Rejette une photo en attente : elle n'est plus exposée mais son fichier est conservé
     */
    #[Route('/{id<\d+>}/reject', name: 'app_moderation_reject', methods: ['POST'])]
    public function reject(Request $request, Picture $picture, EntityManagerInterface $entityManager, PictureFileRemover $pictureFileRemover): Response
    {
        if (!$this->isCsrfTokenValid('reject' . $picture->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Jeton de sécurité invalide, veuillez réessayer.');

            return $this->redirectToRoute('app_moderation_index', [], Response::HTTP_SEE_OTHER);
        }

        if ($picture->getStatus() !== PictureStatus::PENDING) {
            $this->addFlash('warning', 'Cette photo n\'est plus en attente de validation.');

            return $this->redirectToRoute('app_moderation_index', [], Response::HTTP_SEE_OTHER);
        }

        $reason = $this->buildRejectionReason(
            $request->request->get('reason'),
            $request->request->get('details')
        );
        if ($reason === null) {
            $this->addFlash('danger', 'Un motif de refus est obligatoire (précisez-le si vous choisissez « Autre »).');

            return $this->redirectToRoute('app_moderation_index', [], Response::HTTP_SEE_OTHER);
        }

        $picture->setStatus(PictureStatus::REJECTED);
        $picture->setRejectionReason($reason);
        $picture->setModeratedAt(new \DateTimeImmutable());

        // Contenu inapproprié : le fichier est supprimé tout de suite pour ne pas l'exposer, la ligne reste
        // comme « pierre tombale » (motif + notification, imageName et imageUrl à null).
        // Autres motifs : le fichier est conservé jusqu'au « vu » de l'auteur ou à la purge à 30 jours.
        if ($request->request->get('reason') === RejectionReason::INAPPROPRIATE->value) {
            $pictureFileRemover->remove($picture);
        }

        $entityManager->flush();

        $this->addFlash('success', 'Photo rejetée');

        return $this->redirectToRoute('app_moderation_index', [], Response::HTTP_SEE_OTHER);
    }

    /**
     * Construit le motif lisible stocké en base à partir du motif prédéfini et du texte libre.
     * Renvoie null si le motif est absent, inconnu, ou si « Autre » est choisi sans précision.
     */
    private function buildRejectionReason(mixed $reasonValue, mixed $details): ?string
    {
        $reason = is_string($reasonValue) ? RejectionReason::tryFrom($reasonValue) : null;
        $details = is_string($details) ? trim($details) : '';

        if ($reason === null) {
            return null;
        }

        if ($reason === RejectionReason::OTHER) {
            $text = $details;
        } else {
            $text = $details !== '' ? $reason->getLabel() . ' : ' . $details : $reason->getLabel();
        }

        // La colonne est limitée à 255 caractères
        return $text !== '' ? mb_substr($text, 0, 255) : null;
    }
}
