<?php

namespace App\Controller\Api;

use App\Entity\Comment;
use App\Entity\CommentLike;
use App\Entity\Memory;
use App\Entity\User;
use App\Repository\CommentLikeRepository;
use App\Repository\CommentRepository;
use App\Security\RequiresVerifiedEmail;
use App\Security\Voter\CommentVoter;
use App\Service\CommentManager;
use App\Service\CommentNotifier;
use App\Service\CommentProcessor;
use App\Service\LikeNotifier;
use App\Service\ModerationNotifier;
use Doctrine\ORM\EntityManagerInterface;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Commentaires et réponses d'un souvenir. Un seul niveau de réponses ; pas de modération a priori.
 */
class CommentController extends AbstractController
{
    private const LIKERS_LIMIT = 10;

    private const DEFAULT_LIMIT = 10;
    private const MAX_LIMIT = 50;

    private const COMMENT_EXAMPLE = [
        'id' => 1, 'memoryId' => 3, 'parentId' => null,
        'replyToId' => null, 'replyToDisplayName' => null,
        'content' => 'Je me souviens de cette place !', 'createdAt' => '2026-10-27T10:00:00+00:00', 'updatedAt' => null,
        'deleted' => false,
        'author' => ['id' => 5, 'displayName' => 'Jeanne M.'],
        'likesCount' => 2, 'likedByMe' => false,
        'repliesCount' => 1,
        'replies' => [[
            'id' => 2, 'memoryId' => 3, 'parentId' => 1,
            'replyToId' => null, 'replyToDisplayName' => null,
            'content' => 'Moi aussi', 'createdAt' => '2026-10-27T11:00:00+00:00', 'updatedAt' => null,
            'deleted' => false,
            'author' => ['id' => 6, 'displayName' => 'Paul D.'],
            'likesCount' => 0, 'likedByMe' => false,
            'repliesCount' => 0, 'replies' => [],
        ]],
    ];

    /**
     * Commentaires d'un souvenir : racines paginées, chacune avec toutes ses réponses
     */
    #[Route('/api/memory/{id<\d+>}/comments', methods: ['GET'])]
    #[OA\Parameter(name: 'id', in: 'path', required: true, description: 'ID du souvenir', schema: new OA\Schema(type: 'integer'))]
    #[OA\Parameter(name: 'page', in: 'query', description: 'Numéro de page (à partir de 1)', schema: new OA\Schema(type: 'integer', default: 1, minimum: 1))]
    #[OA\Parameter(name: 'limit', in: 'query', description: 'Racines par page (plafonné à 50)', schema: new OA\Schema(type: 'integer', default: 10, minimum: 1, maximum: 50))]
    #[OA\Response(
        response: 200,
        description: 'Racines du plus récent au plus ancien, réponses dans l\'ordre chronologique. `total` = commentaires publics (racines et réponses, hors supprimés et masqués) ; `rootsTotal` = racines affichables (y compris un racine supprimé ou masqué conservé pour ses réponses : `deleted: true`, `content: null`, `author: null`). `likedByMe` est calculé si un JWT valide est envoyé (route publique, JWT facultatif).',
        content: new OA\JsonContent(example: [
            'total' => 2, 'rootsTotal' => 1,
            'pagination' => ['page' => 1, 'limit' => 10],
            'comments' => [self::COMMENT_EXAMPLE],
        ])
    )]
    #[OA\Response(response: 404, description: 'Souvenir introuvable')]
    #[OA\Tag(name: 'comment')]
    public function list(
        int $id,
        Request $request,
        EntityManagerInterface $entityManager,
        CommentRepository $commentRepository,
        CommentProcessor $commentProcessor,
    ): Response {
        $memory = $entityManager->getRepository(Memory::class)->find($id);
        if (!$memory) {
            return $this->json(['error' => 'Souvenir introuvable'], Response::HTTP_NOT_FOUND);
        }

        $page = max(1, $request->query->getInt('page', 1));
        $limit = min(self::MAX_LIMIT, max(1, $request->query->getInt('limit', self::DEFAULT_LIMIT)));
        $viewer = $this->getUser();
        $viewer = $viewer instanceof User ? $viewer : null;

        return $this->json([
            'total' => $commentRepository->countPublic($memory),
            'rootsTotal' => $commentRepository->countDisplayableRoots($memory),
            'pagination' => ['page' => $page, 'limit' => $limit],
            'comments' => $commentProcessor->processRoots($commentRepository->findRootsPage($memory, $page, $limit), $viewer),
        ]);
    }

    /**
     * Ajoute un commentaire (ou une réponse) à un souvenir
     */
    #[Route('/api/secure/memory/{id<\d+>}/comments', methods: ['POST'])]
    #[OA\Parameter(name: 'id', in: 'path', required: true, description: 'ID du souvenir', schema: new OA\Schema(type: 'integer'))]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['content'],
            properties: [
                new OA\Property(property: 'content', type: 'string', minLength: 1, maxLength: 1000, description: 'Texte (1 à 1000 caractères après suppression des espaces autour)', example: 'Je me souviens de cette place !'),
                new OA\Property(property: 'parentId', type: 'integer', nullable: true, description: 'Commentaire auquel répondre. Si c\'est lui-même une réponse, la nouvelle réponse est rattachée au commentaire racine.', example: null),
                new OA\Property(property: 'replyToId', type: 'integer', nullable: true, description: 'Facultatif : commentaire visé par la réponse (le racine ou une réponse du même fil). Son auteur est notifié (`reply_to_comment`). Sans `parentId`, le fil est déduit de ce commentaire. Viser le racine équivaut à ne rien envoyer (`replyToId` renvoyé null).', example: null),
            ]
        )
    )]
    #[OA\Response(response: 201, description: 'Commentaire créé', content: new OA\JsonContent(example: self::COMMENT_EXAMPLE))]
    #[OA\Response(response: 400, description: 'Corps JSON invalide')]
    #[OA\Response(response: 401, description: 'JWT absent ou invalide')]
    #[OA\Response(response: 404, description: 'Souvenir, commentaire parent ou commentaire visé (`replyToId`) introuvable (supprimé, masqué, d\'un autre souvenir ou d\'un autre fil)')]
    #[OA\Response(response: 422, description: 'Contenu vide ou trop long')]
    #[OA\Response(response: 429, description: 'Trop de commentaires en peu de temps (5 par minute et par utilisateur, en-tête Retry-After en secondes)')]
    #[OA\Tag(name: 'comment')]
    #[RequiresVerifiedEmail]
    public function create(
        int $id,
        Request $request,
        EntityManagerInterface $entityManager,
        CommentRepository $commentRepository,
        CommentProcessor $commentProcessor,
        CommentNotifier $commentNotifier,
        RateLimiterFactory $commentCreationLimiter,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();

        $memory = $entityManager->getRepository(Memory::class)->find($id);
        if (!$memory) {
            return $this->json(['error' => 'Souvenir introuvable'], Response::HTTP_NOT_FOUND);
        }

        $data = $this->readBody($request);
        if ($data === null) {
            return $this->json(['error' => 'Corps JSON invalide'], Response::HTTP_BAD_REQUEST);
        }
        $content = $this->cleanContent($data['content'] ?? null);
        if ($error = $this->contentError($content)) {
            return $error;
        }

        $parent = null;
        $replyTo = null;
        $parentId = $data['parentId'] ?? null;
        $replyToId = $data['replyToId'] ?? null;
        if ($parentId !== null || $replyToId !== null) {
            // Un seul niveau : répondre à une réponse revient à répondre à son racine ;
            // sans parentId, le fil se déduit du commentaire visé
            $anchor = $parentId !== null
                ? (is_int($parentId) ? $commentRepository->find($parentId) : null)
                : (is_int($replyToId) ? $commentRepository->find($replyToId) : null);
            $root = $anchor?->getParent() ?? $anchor;
            if (!$root || $root->getMemory() !== $memory || !$root->isPublic() || ($anchor !== $root && !$anchor->isPublic())) {
                return $this->json(['error' => 'Commentaire parent introuvable'], Response::HTTP_NOT_FOUND);
            }
            $parent = $root;

            if ($replyToId !== null) {
                $target = is_int($replyToId) ? $commentRepository->find($replyToId) : null;
                // Même souvenir, même fil, visible
                if (!$target || $target->getMemory() !== $memory || ($target !== $root && $target->getParent() !== $root) || !$target->isPublic()) {
                    return $this->json(['error' => 'Commentaire visé introuvable'], Response::HTTP_NOT_FOUND);
                }
                // Viser le racine, c'est répondre directement : déjà couvert par `parent`
                $replyTo = $target === $root ? null : $target;
            }
        }

        // Anti-spam : limiteur « comment_creation » (config/packages/framework.yaml), un compteur par utilisateur
        $limit = $commentCreationLimiter->create((string) $user->getId())->consume();
        if (!$limit->isAccepted()) {
            return $this->json(
                ['error' => 'Trop de commentaires en peu de temps, réessayez dans une minute'],
                Response::HTTP_TOO_MANY_REQUESTS,
                ['Retry-After' => max(1, $limit->getRetryAfter()->getTimestamp() - time())]
            );
        }

        $comment = (new Comment())
            ->setMemory($memory)
            ->setAuthor($user)
            ->setParent($parent)
            ->setReplyTo($replyTo)
            ->setContent($content);
        $entityManager->persist($comment);
        $commentNotifier->notify($comment);
        $entityManager->flush();

        return $this->json($commentProcessor->process($comment, $user, false), Response::HTTP_CREATED);
    }

    /**
     * Modifie le contenu d'un commentaire (auteur uniquement)
     */
    #[Route('/api/secure/comments/{id<\d+>}', methods: ['PUT'])]
    #[OA\Parameter(name: 'id', in: 'path', required: true, description: 'ID du commentaire', schema: new OA\Schema(type: 'integer'))]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['content'],
            properties: [new OA\Property(property: 'content', type: 'string', minLength: 1, maxLength: 1000, example: 'Texte corrigé')]
        )
    )]
    #[OA\Response(response: 200, description: 'Commentaire modifié (`updatedAt` renseigné ; réponses non incluses)', content: new OA\JsonContent(example: self::COMMENT_EXAMPLE))]
    #[OA\Response(response: 400, description: 'Corps JSON invalide')]
    #[OA\Response(response: 401, description: 'JWT absent ou invalide')]
    #[OA\Response(response: 403, description: 'Pas l\'auteur du commentaire, ou commentaire masqué')]
    #[OA\Response(response: 404, description: 'Commentaire introuvable ou supprimé')]
    #[OA\Response(response: 422, description: 'Contenu vide ou trop long')]
    #[OA\Tag(name: 'comment')]
    public function update(
        int $id,
        Request $request,
        EntityManagerInterface $entityManager,
        CommentRepository $commentRepository,
        CommentProcessor $commentProcessor,
    ): Response {
        $comment = $commentRepository->find($id);
        if (!$comment || $comment->isDeleted()) {
            return $this->json(['error' => 'Commentaire introuvable'], Response::HTTP_NOT_FOUND);
        }
        $this->denyAccessUnlessGranted(CommentVoter::EDIT, $comment);

        $data = $this->readBody($request);
        if ($data === null) {
            return $this->json(['error' => 'Corps JSON invalide'], Response::HTTP_BAD_REQUEST);
        }
        $content = $this->cleanContent($data['content'] ?? null);
        if ($error = $this->contentError($content)) {
            return $error;
        }

        $comment->setContent($content)->setUpdatedAt(new \DateTimeImmutable());
        $entityManager->flush();

        return $this->json($commentProcessor->process($comment, $this->currentUser(), false));
    }

    /**
     * Supprime un commentaire (auteur, administrateur ou modérateur).
     * Réponse ou commentaire sans réponse : supprimé physiquement ; commentaire racine avec réponses : conservé
     * mais vidé et marqué supprimé (`deleted: true`, `content: null`, sans auteur).
     */
    #[Route('/api/secure/comments/{id<\d+>}', methods: ['DELETE'])]
    #[OA\Parameter(name: 'id', in: 'path', required: true, description: 'ID du commentaire', schema: new OA\Schema(type: 'integer'))]
    #[OA\RequestBody(
        required: false,
        description: 'Corps facultatif : motif court (objet) et message libre, communiqués à l\'auteur quand c\'est un administrateur ou un modérateur qui supprime',
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'reason', type: 'string', maxLength: 200, nullable: true, example: 'Propos injurieux'),
            new OA\Property(property: 'message', type: 'string', maxLength: 500, nullable: true, example: 'Merci de rester courtois dans les commentaires.'),
        ])
    )]
    #[OA\Response(response: 204, description: 'Commentaire supprimé. Si l\'acteur n\'est pas l\'auteur, celui-ci reçoit une notification `comment_removed` (GET /api/secure/notifications/comments).')]
    #[OA\Response(response: 422, description: 'Motif (200 caractères au plus) ou message (500 au plus) trop long')]
    #[OA\Response(response: 401, description: 'JWT absent ou invalide')]
    #[OA\Response(response: 403, description: 'Ni l\'auteur, ni administrateur, ni modérateur')]
    #[OA\Response(response: 404, description: 'Commentaire introuvable ou déjà supprimé')]
    #[OA\Tag(name: 'comment')]
    public function delete(
        int $id,
        Request $request,
        EntityManagerInterface $entityManager,
        CommentRepository $commentRepository,
        CommentManager $commentManager,
    ): Response {
        $comment = $commentRepository->find($id);
        if (!$comment || $comment->isDeleted()) {
            return $this->json(['error' => 'Commentaire introuvable'], Response::HTTP_NOT_FOUND);
        }
        $this->denyAccessUnlessGranted(CommentVoter::DELETE, $comment);

        $body = json_decode($request->getContent(), true);
        $body = is_array($body) ? $body : [];
        $reason = $body['reason'] ?? null;
        $message = $body['message'] ?? null;
        if ($error = ModerationNotifier::validationError($reason, $message)) {
            return $this->json(['error' => $error], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $commentManager->delete($comment, $this->currentUser(), ModerationNotifier::normalizeReason($reason), ModerationNotifier::normalizeMessage($message));
        $entityManager->flush();

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Aime ou n'aime plus un commentaire (bascule)
     */
    #[Route('/api/secure/comments/{id<\d+>}/like', methods: ['POST'])]
    #[OA\Parameter(name: 'id', in: 'path', required: true, description: 'ID du commentaire', schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(
        response: 200,
        description: 'État final après la bascule',
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'liked', type: 'boolean', example: true),
            new OA\Property(property: 'likesCount', type: 'integer', example: 3),
        ])
    )]
    #[OA\Response(response: 401, description: 'JWT absent ou invalide')]
    #[OA\Response(response: 404, description: 'Commentaire introuvable, supprimé ou masqué')]
    #[OA\Tag(name: 'comment')]
    public function like(
        int $id,
        EntityManagerInterface $entityManager,
        CommentRepository $commentRepository,
        CommentLikeRepository $commentLikeRepository,
        LikeNotifier $likeNotifier,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();

        $comment = $commentRepository->find($id);
        if (!$comment || !$comment->isPublic()) {
            return $this->json(['error' => 'Commentaire introuvable'], Response::HTTP_NOT_FOUND);
        }

        $like = $commentLikeRepository->findOneBy(['comment' => $comment, 'user' => $user]);
        // Like et notification `comment_liked` dans la même transaction
        $entityManager->wrapInTransaction(function () use ($entityManager, $like, $comment, $user, $likeNotifier) {
            if ($like) {
                $entityManager->remove($like);
                $entityManager->flush();
                $likeNotifier->unliked($comment);
            } else {
                $new = (new CommentLike())->setComment($comment)->setUser($user);
                $entityManager->persist($new);
                $entityManager->flush();
                $likeNotifier->liked($new);
            }
            $entityManager->flush();
        });

        return $this->json([
            'liked' => $like === null,
            'likesCount' => $commentLikeRepository->countForTarget($comment),
        ]);
    }

    /**
     * Personnes ayant aimé un commentaire (infobulle au survol du compteur). Pas de pagination : au plus
     * `LIKERS_LIMIT` (10) likers, le front déduit « et N autres » de `total - likers.length`.
     */
    #[Route('/api/secure/comments/{id<\d+>}/likes', methods: ['GET'])]
    #[OA\Parameter(name: 'id', in: 'path', required: true, description: 'ID du commentaire (racine ou réponse)', schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(
        response: 200,
        description: '`total` = nombre de likes (identique à `likesCount` de la liste des commentaires) ; `likers` = 10 au plus, du like le plus récent au plus ancien, `{id, displayName}` uniquement (jamais d\'email, de rôles ni de date). L\'utilisateur connecté y figure s\'il a aimé. Un compte supprimé n\'apparaît plus.',
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'total', type: 'integer', example: 5),
            new OA\Property(property: 'likers', type: 'array', maxItems: 10, items: new OA\Items(properties: [
                new OA\Property(property: 'id', type: 'integer', example: 7),
                new OA\Property(property: 'displayName', type: 'string', example: 'Paul D.'),
            ], type: 'object')),
        ])
    )]
    #[OA\Response(response: 401, description: 'JWT absent ou invalide')]
    #[OA\Response(response: 404, description: 'Commentaire introuvable, supprimé ou masqué')]
    #[OA\Tag(name: 'comment')]
    public function likes(
        int $id,
        CommentRepository $commentRepository,
        CommentLikeRepository $commentLikeRepository,
        CommentProcessor $commentProcessor,
    ): Response {
        $comment = $commentRepository->find($id);
        if (!$comment || !$comment->isPublic()) {
            return $this->json(['error' => 'Commentaire introuvable'], Response::HTTP_NOT_FOUND);
        }

        return $this->json([
            'total' => $commentLikeRepository->countForTarget($comment),
            'likers' => array_map(
                fn (User $liker) => $commentProcessor->author($liker),
                $commentLikeRepository->findLikers($comment, self::LIKERS_LIMIT)
            ),
        ]);
    }

    /** @return array<string, mixed>|null null si le corps n'est pas un objet JSON */
    private function readBody(Request $request): ?array
    {
        $data = json_decode($request->getContent(), true);

        return is_array($data) ? $data : null;
    }

    /** Contenu saisi, sans espaces en début ni en fin (chaîne vide si ce n'est pas du texte) */
    private function cleanContent(mixed $raw): string
    {
        return is_string($raw) ? trim($raw) : '';
    }

    /** Réponse 422 si le contenu est vide ou trop long, sinon null */
    private function contentError(string $content): ?JsonResponse
    {
        $length = mb_strlen($content);
        if ($length < 1 || $length > Comment::MAX_LENGTH) {
            return $this->json(
                ['error' => sprintf('Le commentaire doit comporter entre 1 et %d caractères.', Comment::MAX_LENGTH)],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        return null;
    }

    /** Utilisateur connecté, ou null (anonyme) */
    private function currentUser(): ?User
    {
        $user = $this->getUser();

        return $user instanceof User ? $user : null;
    }
}
