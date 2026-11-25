<?php

namespace App\Controller\Api;

use App\Entity\User;
use App\Enum\PictureStatus;
use App\Repository\NotificationRepository;
use App\Repository\PictureRepository;
use App\Service\CommentProcessor;
use Doctrine\ORM\EntityManagerInterface;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Notifications de l'utilisateur connecté (le front les récupère par polling) :
 * - `/api/secure/notifications` : refus de ses photos proposées (calculés depuis Picture) ;
 * - `/api/secure/notifications/comments` : commentaires et réponses (entité Notification).
 * Les deux contrats sont séparés à titre transitoire, en attendant une éventuelle unification.
 */
class NotificationController extends AbstractController
{
    private const EXCERPT_LENGTH = 100;
    private const MODERATION_NAME = 'Modération';
    // Garde-fou : une page de notifications n'en affiche jamais autant
    private const MAX_BULK_IDS = 200;

    /**
     * Refus de photos non encore vus par l'utilisateur connecté
     */
    #[Route('/api/secure/notifications', methods: ['GET'])]
    #[OA\Response(
        response: 200,
        description: 'Photos refusées dont la notification n\'a pas encore été vue, de la plus récente à la plus ancienne',
        content: new OA\JsonContent(
            type: 'array',
            items: new OA\Items(
                properties: [
                    new OA\Property(property: 'pictureId', type: 'integer', example: 12),
                    new OA\Property(property: 'memoryId', type: 'integer', example: 3),
                    new OA\Property(property: 'memoryTitle', type: 'string', nullable: true, example: 'La place du marché'),
                    new OA\Property(property: 'imageUrl', type: 'string', nullable: true, description: 'Photo refusée ; null si le fichier a été supprimé (contenu inapproprié)', example: '/uploads/memories/abc123.jpg'),
                    new OA\Property(property: 'reason', type: 'string', nullable: true, example: 'Photo floue ou illisible'),
                    new OA\Property(property: 'moderatedAt', type: 'string', format: 'date-time', nullable: true, example: '2026-10-27T10:00:00+00:00'),
                ],
                type: 'object'
            )
        )
    )]
    #[OA\Response(response: 401, description: 'JWT absent ou invalide')]
    #[OA\Tag(name: 'notification')]
    public function index(PictureRepository $pictureRepository): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $notifications = array_map(fn ($picture) => [
            'pictureId' => $picture->getId(),
            'memoryId' => $picture->getMemory()?->getId(),
            'memoryTitle' => $picture->getMemory()?->getTitle(),
            'imageUrl' => $picture->getImageUrl(),
            'reason' => $picture->getRejectionReason(),
            'moderatedAt' => $picture->getModeratedAt()?->format(\DateTimeInterface::ATOM),
        ], $pictureRepository->findUnseenRejectedByUser($user));

        return $this->json($notifications);
    }

    /**
     * Acquitte la notification de refus : la photo refusée est supprimée (fichier s'il existe, puis ligne).
     * Une fois acquittée la photo n'existe plus : un second appel renvoie donc 404, sans autre effet.
     */
    #[Route('/api/secure/notifications/{pictureId<\d+>}/seen', methods: ['POST'])]
    #[OA\Parameter(name: 'pictureId', in: 'path', required: true, description: 'ID de la photo refusée', schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(
        response: 200,
        description: 'Notification acquittée, photo supprimée',
        content: new OA\JsonContent(properties: [new OA\Property(property: 'message', type: 'string')])
    )]
    #[OA\Response(response: 401, description: 'JWT absent ou invalide')]
    #[OA\Response(response: 404, description: 'Photo introuvable, déjà acquittée, non refusée ou n\'appartenant pas à l\'utilisateur connecté')]
    #[OA\Tag(name: 'notification')]
    public function seen(
        int $pictureId,
        PictureRepository $pictureRepository,
        EntityManagerInterface $entityManager,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();

        $picture = $pictureRepository->find($pictureId);
        // 404 identique que la photo n'existe pas (ou plus), ne soit pas refusée ou appartienne à un autre utilisateur
        if (!$picture || $picture->getUploadedBy() !== $user || $picture->getStatus() !== PictureStatus::REJECTED) {
            return $this->json(['error' => 'Notification introuvable'], Response::HTTP_NOT_FOUND);
        }

        // L'auteur a vu le refus : la photo et sa notification n'ont plus lieu d'être.
        // Vich supprime le fichier avec l'entité (sans erreur s'il est déjà absent).
        $entityManager->remove($picture);
        $entityManager->flush();

        return $this->json(['message' => 'Notification marquée comme vue']);
    }

    /**
     * Notifications de commentaires et de modération non vues (étape intermédiaire : distinctes des refus de
     * photos, qui restent sur GET /api/secure/notifications)
     */
    #[Route('/api/secure/notifications/comments', methods: ['GET'])]
    #[OA\Response(
        response: 200,
        description: 'Notifications non vues, de la plus récente à la plus ancienne. Types `comment_on_memory` et `reply_to_comment` : un commentaire a été écrit (`commentId` renseigné, `reason` null). Type `comment_restored` : un commentaire masqué par la modération est de nouveau visible (`commentId` renseigné, `excerpt` = début du commentaire). Type `picture_removed` : une photo proposée par l\'utilisateur a été supprimée par la modération (`memoryId` et `memoryTitle` renseignés tant que le souvenir existe, `commentId` et `excerpt` null). Type `picture_approved` : une photo proposée par l\'utilisateur a été validée par la modération (`memoryId` et `memoryTitle` renseignés tant que le souvenir existe, `commentId`, `excerpt`, `reason` et `message` null, `actorDisplayName` « Modération »). Type `report_reviewed` : la modération a examiné un signalement de l\'utilisateur ; notification volontairement neutre, identique quelle que soit la décision (`memoryId`, `memoryTitle`, `commentId`, `excerpt`, `reason` et `message` null, `actorDisplayName` « Modération »). Types `comment_liked` et `memory_liked` : quelqu\'un a aimé un commentaire, une réponse ou un souvenir de l\'utilisateur ; une seule notification non vue par commentaire ou souvenir (`commentId` pour l\'ancre, null pour `memory_liked` ; `actorDisplayName` = dernier liker, `likesCount` = nombre de likers depuis la dernière vue, `reason` et `message` null). Type `location_new_memory` : un autre utilisateur a publié un souvenir sur une localisation où l\'utilisateur a déjà un souvenir (`memoryId` et `memoryTitle` = nouveau souvenir, `actorDisplayName` = son auteur, `commentId`, `excerpt`, `reason`, `message` et `likesCount` null). Types `current_picture_replacement_proposed` et `current_picture_replaced` : un autre utilisateur propose de remplacer (puis, une fois validé par la modération, a remplacé et supprimé) la photo actuelle que l\'utilisateur avait déposée (`memoryId` et `memoryTitle` = souvenir, `actorDisplayName` = le proposant, `commentId`, `excerpt`, `reason`, `message` et `likesCount` null). Types `memory_deleted` et `comment_removed` : un administrateur ou un modérateur a retiré un contenu de l\'utilisateur ; `commentId` est null (le commentaire n\'existe plus ou est vidé), `memoryId` est null pour `memory_deleted` (le souvenir n\'existe plus : utiliser `memoryTitle`, sans lien), `actorDisplayName` vaut « Modération » (l\'identité du modérateur n\'est pas exposée) et `reason` peut contenir un motif court (objet) et `message` un texte libre.',
        content: new OA\JsonContent(
            type: 'array',
            items: new OA\Items(
                properties: [
                    new OA\Property(property: 'id', type: 'integer', example: 7),
                    new OA\Property(property: 'type', type: 'string', enum: ['comment_on_memory', 'reply_to_comment', 'memory_deleted', 'comment_removed', 'comment_restored', 'picture_removed', 'picture_approved', 'report_reviewed', 'comment_liked', 'memory_liked', 'location_new_memory', 'current_picture_replacement_proposed', 'current_picture_replaced'], example: 'reply_to_comment'),
                    new OA\Property(property: 'memoryId', type: 'integer', nullable: true, description: 'Null pour `memory_deleted` (et si le souvenir a été supprimé depuis)', example: 3),
                    new OA\Property(property: 'memoryTitle', type: 'string', nullable: true, example: 'La place du marché'),
                    new OA\Property(property: 'commentId', type: 'integer', nullable: true, example: 12),
                    new OA\Property(property: 'actorDisplayName', type: 'string', description: '« Utilisateur supprimé » si l\'auteur du commentaire n\'existe plus ; « Modération » pour les types memory_deleted, comment_removed, comment_restored, picture_removed, picture_approved et report_reviewed', example: 'Paul D.'),
                    new OA\Property(property: 'excerpt', type: 'string', nullable: true, description: 'Début du commentaire (100 caractères au plus, « … » si tronqué) ; null pour `memory_deleted`', example: 'Moi aussi je me souviens…'),
                    new OA\Property(property: 'reason', type: 'string', nullable: true, description: 'Motif court de la modération (types de modération uniquement)', example: 'Contenu offensant'),
                    new OA\Property(property: 'message', type: 'string', nullable: true, description: 'Message libre de la modération (500 caractères au plus), en plus du motif ; null pour les commentaires et réponses, et quand le modérateur n\'en a pas écrit', example: 'Merci de rester courtois dans les commentaires.'),
                    new OA\Property(property: 'likesCount', type: 'integer', nullable: true, description: 'Types `comment_liked` et `memory_liked` uniquement (entier >= 1) : personnes ayant aimé le commentaire depuis la dernière vue ; null pour les autres types', example: 2),
                    new OA\Property(property: 'createdAt', type: 'string', format: 'date-time', description: 'Pour `comment_liked` : date du dernier like', example: '2026-10-27T10:00:00+00:00'),
                ],
                type: 'object'
            )
        )
    )]
    #[OA\Response(response: 401, description: 'JWT absent ou invalide')]
    #[OA\Tag(name: 'notification')]
    public function comments(NotificationRepository $notificationRepository): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        return $this->json(array_map(function ($notification) {
            $type = $notification->getType();
            $content = $notification->getComment()?->getContent();
            $excerpt = $type->isModeration() ? $notification->getExcerpt() : $content;
            $memory = $notification->getMemory();

            return [
                'id' => $notification->getId(),
                'type' => $type->value,
                'memoryId' => $memory?->getId(),
                'memoryTitle' => $memory?->getTitle() ?? $notification->getMemoryTitle(),
                'commentId' => $notification->getComment()?->getId(),
                'actorDisplayName' => $type->isModeration()
                    ? self::MODERATION_NAME
                    : ($notification->getActor()?->getDisplayName() ?? CommentProcessor::DELETED_USER_NAME),
                'excerpt' => $excerpt === null ? null : (mb_strlen($excerpt) > self::EXCERPT_LENGTH ? mb_substr($excerpt, 0, self::EXCERPT_LENGTH) . '…' : $excerpt),
                'reason' => $notification->getReason(),
                'message' => $notification->getMessage(),
                'likesCount' => $type->isLike() ? $notification->getLikesCount() : null,
                'createdAt' => $notification->getCreatedAt()->format(\DateTimeInterface::ATOM),
            ];
        }, $notificationRepository->findUnseenByRecipient($user)));
    }

    /**
     * Marque une notification de commentaire comme vue (idempotent : un second appel renvoie aussi 200)
     */
    #[Route('/api/secure/notifications/comments/{id<\d+>}/seen', methods: ['POST'])]
    #[OA\Parameter(name: 'id', in: 'path', required: true, description: 'ID de la notification', schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(
        response: 200,
        description: 'Notification marquée comme vue',
        content: new OA\JsonContent(properties: [new OA\Property(property: 'message', type: 'string')])
    )]
    #[OA\Response(response: 401, description: 'JWT absent ou invalide')]
    #[OA\Response(response: 404, description: 'Notification introuvable ou n\'appartenant pas à l\'utilisateur connecté')]
    #[OA\Tag(name: 'notification')]
    public function commentSeen(
        int $id,
        NotificationRepository $notificationRepository,
        EntityManagerInterface $entityManager,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();

        $notification = $notificationRepository->find($id);
        if (!$notification || $notification->getRecipient()?->getId() !== $user->getId()) {
            return $this->json(['error' => 'Notification introuvable'], Response::HTTP_NOT_FOUND);
        }

        if ($notification->getSeenAt() === null) {
            $notification->setSeenAt(new \DateTimeImmutable());
            $entityManager->flush();
        }

        return $this->json(['message' => 'Notification marquée comme vue']);
    }

    /**
     * Marque plusieurs notifications comme vues en un seul appel (« tout marquer comme lu »). Le front envoie les
     * identifiants qu'il affiche : une notification arrivée entre-temps n'est donc pas acquittée sans avoir été vue.
     * Mêmes effets que les routes `seen` unitaires (un refus de photo acquitté supprime la photo), mais tolérant :
     * un identifiant inconnu, déjà acquitté ou d'un autre utilisateur est ignoré, sans 404.
     */
    #[Route('/api/secure/notifications/seen', methods: ['POST'])]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'commentIds', type: 'array', description: 'IDs de notifications (GET /api/secure/notifications/comments)', items: new OA\Items(type: 'integer'), example: [7, 8]),
            new OA\Property(property: 'pictureIds', type: 'array', description: 'IDs de photos refusées (GET /api/secure/notifications)', items: new OA\Items(type: 'integer'), example: [12]),
        ])
    )]
    #[OA\Response(
        response: 200,
        description: 'Notifications acquittées',
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'commentsMarked', type: 'integer', description: 'Notifications de commentaires marquées comme vues', example: 2),
            new OA\Property(property: 'picturesRemoved', type: 'integer', description: 'Photos refusées acquittées (donc supprimées)', example: 1),
        ])
    )]
    #[OA\Response(response: 401, description: 'JWT absent ou invalide')]
    #[OA\Response(response: 422, description: 'Corps invalide : tableaux d\'entiers positifs, 200 au plus chacun')]
    #[OA\Tag(name: 'notification')]
    public function seenMany(
        Request $request,
        NotificationRepository $notificationRepository,
        PictureRepository $pictureRepository,
        EntityManagerInterface $entityManager,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['error' => 'Corps JSON invalide'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $lists = [];
        foreach (['commentIds', 'pictureIds'] as $key) {
            $ids = $data[$key] ?? [];
            if (!is_array($ids) || count($ids) > self::MAX_BULK_IDS) {
                return $this->json(['error' => sprintf('« %s » doit être un tableau de %d identifiants au plus', $key, self::MAX_BULK_IDS)], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            foreach ($ids as $id) {
                if (!is_int($id) || $id < 1) {
                    return $this->json(['error' => sprintf('« %s » ne doit contenir que des entiers positifs', $key)], Response::HTTP_UNPROCESSABLE_ENTITY);
                }
            }
            $lists[$key] = array_values(array_unique($ids));
        }

        $commentsMarked = $notificationRepository->markSeen($user, $lists['commentIds'], new \DateTimeImmutable());

        // Comme la route unitaire : le refus est acquitté, la photo n'a plus lieu d'être (Vich supprime aussi le fichier)
        $picturesRemoved = 0;
        $pictures = $lists['pictureIds'] ? $pictureRepository->findBy(['id' => $lists['pictureIds']]) : [];
        foreach ($pictures as $picture) {
            if ($picture->getUploadedBy() === $user && $picture->getStatus() === PictureStatus::REJECTED) {
                $entityManager->remove($picture);
                ++$picturesRemoved;
            }
        }
        $entityManager->flush();

        return $this->json(['commentsMarked' => $commentsMarked, 'picturesRemoved' => $picturesRemoved]);
    }
}
