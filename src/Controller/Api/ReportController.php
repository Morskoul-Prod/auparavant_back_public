<?php

namespace App\Controller\Api;

use App\Entity\Report;
use App\Entity\User;
use App\Enum\ReportReason;
use App\Enum\ReportTargetType;
use App\Repository\ReportRepository;
use App\Service\ReportTargetResolver;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Signalement de contenu par un utilisateur connecté. Traité ensuite dans le back-office (/back/reports/).
 */
class ReportController extends AbstractController
{
    /**
     * Signale un contenu : commentaire, souvenir ou photo
     */
    #[Route('/api/secure/reports', methods: ['POST'])]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['targetType', 'targetId', 'reason'],
            properties: [
                new OA\Property(property: 'targetType', type: 'string', enum: ['memory', 'picture', 'comment'], description: 'Type de contenu signalé', example: 'comment'),
                new OA\Property(property: 'targetId', type: 'integer', example: 12),
                new OA\Property(property: 'reason', type: 'string', enum: ['spam', 'illegal', 'offensive', 'copyright', 'other'], example: 'spam'),
                new OA\Property(property: 'details', type: 'string', nullable: true, maxLength: 500, description: 'Précisions facultatives', example: 'Publicité répétée'),
            ]
        )
    )]
    #[OA\Response(
        response: 201,
        description: 'Signalement enregistré',
        content: new OA\JsonContent(example: [
            'id' => 4, 'targetType' => 'comment', 'targetId' => 12, 'reason' => 'spam',
            'status' => 'open', 'createdAt' => '2026-10-27T10:00:00+00:00',
        ])
    )]
    #[OA\Response(response: 400, description: 'Corps JSON invalide')]
    #[OA\Response(response: 401, description: 'JWT absent ou invalide')]
    #[OA\Response(response: 403, description: 'On ne peut pas signaler son propre contenu (souvenir créé, photo proposée ou commentaire écrit par soi)')]
    #[OA\Response(response: 404, description: 'Cible introuvable (inexistante, commentaire supprimé ou masqué, photo non approuvée)')]
    #[OA\Response(response: 409, description: 'Contenu déjà signalé par cet utilisateur')]
    #[OA\Response(response: 422, description: 'Type de cible non pris en charge, identifiant, motif ou précisions invalides')]
    #[OA\Tag(name: 'report')]
    public function create(
        Request $request,
        ReportRepository $reportRepository,
        ReportTargetResolver $targetResolver,
        EntityManagerInterface $entityManager,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['error' => 'Corps JSON invalide'], Response::HTTP_BAD_REQUEST);
        }

        $type = is_string($data['targetType'] ?? null) ? ReportTargetType::tryFrom($data['targetType']) : null;
        if ($type === null) {
            return $this->json(['error' => 'Type de cible invalide (memory, picture ou comment)'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $targetId = $data['targetId'] ?? null;
        if (!is_int($targetId) || $targetId < 1) {
            return $this->json(['error' => 'Identifiant de cible invalide'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $reason = is_string($data['reason'] ?? null) ? ReportReason::tryFrom($data['reason']) : null;
        if ($reason === null) {
            return $this->json(['error' => 'Motif invalide (spam, illegal, offensive, copyright ou other)'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $details = $data['details'] ?? null;
        if ($details !== null && !is_string($details)) {
            return $this->json(['error' => 'Précisions invalides'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $details = $details !== null ? trim($details) : null;
        if ($details !== null && mb_strlen($details) > Report::MAX_DETAILS_LENGTH) {
            return $this->json(['error' => sprintf('Les précisions ne peuvent pas dépasser %d caractères.', Report::MAX_DETAILS_LENGTH)], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $target = $targetResolver->findReportable($type, $targetId);
        if ($target === null) {
            return $this->json(['error' => 'Contenu introuvable'], Response::HTTP_NOT_FOUND);
        }
        if ($targetResolver->getAuthor($target)?->getId() === $user->getId()) {
            return $this->json(['error' => 'Vous ne pouvez pas signaler votre propre contenu'], Response::HTTP_FORBIDDEN);
        }
        if ($reportRepository->findOneByReporterAndTarget($user, $type, $targetId)) {
            return $this->json(['error' => 'Vous avez déjà signalé ce contenu'], Response::HTTP_CONFLICT);
        }

        $report = (new Report($type, $targetId, $reason))
            ->setReporter($user)
            ->setDetails($details !== '' ? $details : null);
        $entityManager->persist($report);
        try {
            $entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            // Deux requêtes simultanées du même utilisateur
            return $this->json(['error' => 'Vous avez déjà signalé ce contenu'], Response::HTTP_CONFLICT);
        }

        return $this->json([
            'id' => $report->getId(),
            'targetType' => $type->value,
            'targetId' => $targetId,
            'reason' => $reason->value,
            'status' => $report->getStatus()->value,
            'createdAt' => $report->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ], Response::HTTP_CREATED);
    }
}
