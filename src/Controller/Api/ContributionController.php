<?php

namespace App\Controller\Api;

use App\Entity\Comment;
use App\Entity\Picture;
use App\Entity\User;
use App\Enum\PictureStatus;
use App\Repository\CommentLikeRepository;
use App\Repository\CommentRepository;
use App\Repository\MemoryRepository;
use App\Repository\PictureRepository;
use App\Service\MemoryProcessor;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Contributions de l'utilisateur connecté (tableau de bord « Mes contributions » du front).
 * Souvenirs, photos actuelles proposées et commentaires (racines et réponses) de l'utilisateur.
 */
class ContributionController extends AbstractController
{
    private const DEFAULT_LIMIT = 20;
    private const MAX_LIMIT = 50;

    /**
     * Souvenirs créés, photos actuelles proposées et commentaires de l'utilisateur connecté
     */
    #[Route('/api/secure/my-contributions', methods: ['GET'])]
    #[OA\Parameter(name: 'page', in: 'query', description: 'Numéro de page (à partir de 1), commun aux souvenirs, aux photos et aux commentaires', schema: new OA\Schema(type: 'integer', default: 1, minimum: 1))]
    #[OA\Parameter(name: 'limit', in: 'query', description: 'Éléments par page pour chaque liste (plafonné à 50)', schema: new OA\Schema(type: 'integer', default: 20, minimum: 1, maximum: 50))]
    #[OA\Parameter(name: 'status', in: 'query', description: 'Ne renvoie que les photos de ce statut (liste « pictures » uniquement ; les souvenirs et les compteurs ne sont pas filtrés)', schema: new OA\Schema(type: 'string', enum: ['pending', 'approved', 'rejected']))]
    #[OA\Response(
        response: 200,
        description: 'Contributions de l\'utilisateur connecté, de la plus récente à la plus ancienne. Les photos dont le refus a été acquitté (notification vue) sont supprimées et n\'apparaissent plus.',
        content: new OA\JsonContent(
            example: [
                'stats' => ['memories' => 12, 'pictures' => 3, 'picturesPending' => 1, 'picturesApproved' => 1, 'picturesRejected' => 1, 'comments' => 4],
                'pagination' => ['page' => 1, 'limit' => 20],
                'memories' => [
                    [
                        'id' => 1,
                        'title' => 'Le Panthéon en 1950',
                        'content' => 'Photo de famille devant le Panthéon',
                        'picture_date' => null,
                        'year' => 1950,
                        'main_picture' => 'abc123.jpg',
                        'compare_picture' => null,
                        'location' => ['id' => 1, 'area' => 'Île-de-France', 'department' => 'Paris', 'district' => 'Quartier latin', 'street' => 'Place du Panthéon', 'city' => 'Paris', 'zipcode' => 75005, 'latitude' => '48.84619800', 'longitude' => '2.34610500', 'address_id' => null],
                        'pictures' => [['id' => 1, 'imageName' => 'abc123.jpg', 'imageUrl' => '/uploads/memories/abc123.jpg', 'type' => 'before', 'position' => 0, 'status' => 'approved', 'uploadedBy' => 4]],
                        'user' => ['id' => 4, 'displayName' => 'Jeanne'],
                        'place' => ['id' => 1, 'name' => 'Le Panthéon', 'type' => 'maison'],
                    ],
                ],
                'pictures' => [
                    [
                        'id' => 12,
                        'imageUrl' => '/uploads/memories/def456.jpg',
                        'status' => 'rejected',
                        'rejectionReason' => 'Photo floue ou illisible',
                        'createdAt' => '2026-10-26T09:00:00+00:00',
                        'moderatedAt' => '2026-10-27T10:00:00+00:00',
                        'memoryId' => 3,
                        'memoryTitle' => 'La place du marché',
                        'memoryMainPicture' => ['imageName' => 'ghi789.jpg', 'imageUrl' => '/uploads/memories/ghi789.jpg'],
                    ],
                ],
                'comments' => [
                    [
                        'id' => 21,
                        'content' => 'Je me souviens de cette place !',
                        'createdAt' => '2026-10-27T10:00:00+00:00',
                        'memoryId' => 3,
                        'memoryTitle' => 'La place du marché',
                        'parentId' => null,
                        'likesCount' => 2,
                    ],
                ],
            ]
        )
    )]
    #[OA\Response(response: 400, description: 'Statut inconnu')]
    #[OA\Response(response: 401, description: 'JWT absent ou invalide')]
    #[OA\Tag(name: 'user')]
    public function index(
        Request $request,
        MemoryRepository $memoryRepository,
        PictureRepository $pictureRepository,
        MemoryProcessor $memoryProcessor,
        CommentRepository $commentRepository,
        CommentLikeRepository $commentLikeRepository,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        $page = max(1, $request->query->getInt('page', 1));
        $limit = min(self::MAX_LIMIT, max(1, $request->query->getInt('limit', self::DEFAULT_LIMIT)));

        $statusParam = $request->query->get('status');
        $status = null;
        if ($statusParam !== null && $statusParam !== '') {
            $status = PictureStatus::tryFrom((string) $statusParam);
            if ($status === null) {
                return $this->json('Statut inconnu', 400);
            }
        }

        $counts = $pictureRepository->countAfterByUploaderGroupedByStatus($user);
        $pictures = $pictureRepository->findAfterByUploader($user, $page, $limit, $status);
        // Photos des souvenirs liés chargées d'un coup, pour la photo principale
        $memoryRepository->loadPictures(array_map(fn (Picture $p) => $p->getMemory(), $pictures));

        $comments = $commentRepository->findByAuthor($user, $page, $limit);
        $likesCounts = $commentLikeRepository->countByComments(array_map(fn (Comment $c) => $c->getId(), $comments));

        return $this->json([
            'stats' => [
                'memories' => $memoryRepository->countByUser($user),
                'pictures' => array_sum($counts),
                'picturesPending' => $counts[PictureStatus::PENDING->value] ?? 0,
                'picturesApproved' => $counts[PictureStatus::APPROVED->value] ?? 0,
                'picturesRejected' => $counts[PictureStatus::REJECTED->value] ?? 0,
                'comments' => $commentRepository->countByAuthor($user),
            ],
            'pagination' => ['page' => $page, 'limit' => $limit],
            'memories' => $memoryProcessor->processMemories($memoryRepository->findByUser($user, $page, $limit)),
            'pictures' => array_map(fn (Picture $picture) => $this->pictureToArray($picture), $pictures),
            'comments' => array_map(fn (Comment $comment) => $this->commentToArray($comment, $likesCounts[$comment->getId()] ?? 0), $comments),
        ]);
    }

    private function pictureToArray(Picture $picture): array
    {
        $memory = $picture->getMemory();
        $main = $memory->getApprovedPictures()[0] ?? null;

        return [
            'id' => $picture->getId(),
            'imageUrl' => $picture->getImageUrl(),
            'status' => $picture->getStatus()->value,
            'rejectionReason' => $picture->isRejected() ? $picture->getRejectionReason() : null,
            'createdAt' => $picture->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            'moderatedAt' => $picture->getModeratedAt()?->format(\DateTimeInterface::ATOM),
            'memoryId' => $memory->getId(),
            'memoryTitle' => $memory->getTitle(),
            'memoryMainPicture' => $main ? ['imageName' => $main->getImageName(), 'imageUrl' => $main->getImageUrl()] : null,
        ];
    }

    private function commentToArray(Comment $comment, int $likesCount): array
    {
        return [
            'id' => $comment->getId(),
            'content' => $comment->getContent(),
            'createdAt' => $comment->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'memoryId' => $comment->getMemory()->getId(),
            'memoryTitle' => $comment->getMemory()->getTitle(),
            'parentId' => $comment->getParent()?->getId(),
            'likesCount' => $likesCount,
        ];
    }
}
