<?php

namespace App\Controller\Api;

use App\Entity\MemoryLike;
use App\Entity\User;
use App\Repository\MemoryLikeRepository;
use App\Repository\MemoryRepository;
use App\Service\CommentProcessor;
use App\Service\LikeNotifier;
use Doctrine\ORM\EntityManagerInterface;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * « J'aime » sur un souvenir, en miroir de ceux des commentaires ; l'auteur du souvenir est notifié (`memory_liked`).
 */
class MemoryLikeController extends AbstractController
{
    private const LIKERS_LIMIT = 10;

    /**
     * Aime ou n'aime plus un souvenir (bascule)
     */
    #[Route('/api/secure/memory/{id<\d+>}/like', methods: ['POST'])]
    #[OA\Parameter(name: 'id', in: 'path', required: true, description: 'ID du souvenir', schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(
        response: 200,
        description: 'État final après la bascule',
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'liked', type: 'boolean', example: true),
            new OA\Property(property: 'likesCount', type: 'integer', example: 3),
        ])
    )]
    #[OA\Response(response: 401, description: 'JWT absent ou invalide')]
    #[OA\Response(response: 404, description: 'Souvenir introuvable')]
    #[OA\Tag(name: 'memory')]
    public function like(
        int $id,
        EntityManagerInterface $entityManager,
        MemoryRepository $memoryRepository,
        MemoryLikeRepository $memoryLikeRepository,
        LikeNotifier $likeNotifier,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();

        $memory = $memoryRepository->find($id);
        if (!$memory) {
            return $this->json(['error' => 'Souvenir introuvable'], Response::HTTP_NOT_FOUND);
        }

        $like = $memoryLikeRepository->findOneBy(['memory' => $memory, 'user' => $user]);
        // Like et notification `memory_liked` dans la même transaction
        $entityManager->wrapInTransaction(function () use ($entityManager, $like, $memory, $user, $likeNotifier) {
            if ($like) {
                $entityManager->remove($like);
                $entityManager->flush();
                $likeNotifier->unliked($memory);
            } else {
                $new = (new MemoryLike())->setMemory($memory)->setUser($user);
                $entityManager->persist($new);
                $entityManager->flush();
                $likeNotifier->liked($new);
            }
            $entityManager->flush();
        });

        return $this->json([
            'liked' => $like === null,
            'likesCount' => $memoryLikeRepository->countForTarget($memory),
        ]);
    }

    /**
     * Personnes ayant aimé un souvenir (infobulle au survol du compteur). Pas de pagination : au plus
     * `LIKERS_LIMIT` (10) likers, le front déduit « et N autres » de `total - likers.length`.
     */
    #[Route('/api/secure/memory/{id<\d+>}/likes', methods: ['GET'])]
    #[OA\Parameter(name: 'id', in: 'path', required: true, description: 'ID du souvenir', schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(
        response: 200,
        description: '`total` = nombre de likes (identique à `likesCount` de `GET /api/memory/{id}`) ; `likers` = 10 au plus, du like le plus récent au plus ancien, `{id, displayName}` uniquement (jamais d\'email, de rôles ni de date). L\'utilisateur connecté y figure s\'il a aimé. Un compte supprimé n\'apparaît plus.',
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'total', type: 'integer', example: 5),
            new OA\Property(property: 'likers', type: 'array', maxItems: 10, items: new OA\Items(properties: [
                new OA\Property(property: 'id', type: 'integer', example: 7),
                new OA\Property(property: 'displayName', type: 'string', example: 'Paul D.'),
            ], type: 'object')),
        ])
    )]
    #[OA\Response(response: 401, description: 'JWT absent ou invalide')]
    #[OA\Response(response: 404, description: 'Souvenir introuvable')]
    #[OA\Tag(name: 'memory')]
    public function likes(
        int $id,
        MemoryRepository $memoryRepository,
        MemoryLikeRepository $memoryLikeRepository,
        CommentProcessor $commentProcessor,
    ): Response {
        $memory = $memoryRepository->find($id);
        if (!$memory) {
            return $this->json(['error' => 'Souvenir introuvable'], Response::HTTP_NOT_FOUND);
        }

        return $this->json([
            'total' => $memoryLikeRepository->countForTarget($memory),
            'likers' => array_map(
                fn (User $liker) => $commentProcessor->author($liker),
                $memoryLikeRepository->findLikers($memory, self::LIKERS_LIMIT)
            ),
        ]);
    }
}
