<?php

namespace App\Service;

use App\Entity\Comment;
use App\Entity\User;
use App\Repository\CommentLikeRepository;

/**
 * Construit le tableau JSON des commentaires renvoyé par l'API (contrat consommé par le front).
 * N'expose jamais l'email ni les rôles de l'auteur : uniquement `id` et `displayName`.
 */
class CommentProcessor
{
    public const DELETED_USER_NAME = 'Utilisateur supprimé';

    public function __construct(private readonly CommentLikeRepository $commentLikeRepository)
    {
    }

    /**
     * @param Comment[] $roots racines dont `replies` ne contient que les réponses à afficher
     * @return array<int, array<string, mixed>>
     */
    public function processRoots(array $roots, ?User $viewer): array
    {
        $ids = [];
        foreach ($roots as $root) {
            $ids[] = $root->getId();
            foreach ($root->getReplies() as $reply) {
                $ids[] = $reply->getId();
            }
        }
        $likes = $this->likeStats($ids, $viewer);

        return array_map(fn (Comment $root) => $this->build($root, $viewer, $likes, true), $roots);
    }

    /**
     * @param bool $withReplies false pour une réponse ou un commentaire fraîchement créé
     * @return array<string, mixed>
     */
    public function process(Comment $comment, ?User $viewer, bool $withReplies = true): array
    {
        $ids = [$comment->getId()];
        if ($withReplies) {
            foreach ($comment->getReplies() as $reply) {
                $ids[] = $reply->getId();
            }
        }

        return $this->build($comment, $viewer, $this->likeStats($ids, $viewer), $withReplies);
    }

    /**
     * Likes de tous les commentaires de la réponse, calculés par deux requêtes groupées (pas de N+1)
     *
     * @param int[] $ids
     * @return array{counts: array<int, int>, liked: array<int, true>}
     */
    private function likeStats(array $ids, ?User $viewer): array
    {
        return [
            'counts' => $this->commentLikeRepository->countByComments($ids),
            'liked' => $viewer ? $this->commentLikeRepository->likedByUser($viewer, $ids) : [],
        ];
    }

    /**
     * @param array{counts: array<int, int>, liked: array<int, true>} $likes
     * @return array<string, mixed>
     */
    private function build(Comment $comment, ?User $viewer, array $likes, bool $withReplies): array
    {
        // Un racine supprimé ou masqué (conservé pour ses réponses) est renvoyé sans contenu ni auteur
        $public = $comment->isPublic();
        $replies = $withReplies && !$comment->isReply()
            ? array_map(fn (Comment $reply) => $this->build($reply, $viewer, $likes, false), $comment->getReplies()->toArray())
            : [];

        // Commentaire visé : absent s'il a été supprimé (clé étrangère) ou masqué depuis
        $replyTo = $comment->getReplyTo();
        $replyTo = $replyTo !== null && $replyTo->isPublic() ? $replyTo : null;

        return [
            'id' => $comment->getId(),
            'memoryId' => $comment->getMemory()?->getId(),
            'parentId' => $comment->getParent()?->getId(),
            'replyToId' => $replyTo?->getId(),
            'replyToDisplayName' => $replyTo !== null ? $this->author($replyTo->getAuthor())['displayName'] : null,
            'content' => $public ? $comment->getContent() : null,
            'createdAt' => $comment->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updatedAt' => $comment->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
            'deleted' => !$public,
            'author' => $public ? $this->author($comment->getAuthor()) : null,
            'likesCount' => $public ? ($likes['counts'][$comment->getId()] ?? 0) : 0,
            'likedByMe' => $public && isset($likes['liked'][$comment->getId()]),
            'repliesCount' => count($replies),
            'replies' => $replies,
        ];
    }

    /** Format public d'un utilisateur (auteur ou liker) : id et nom affiché, jamais d'email ni de rôles @return array{id: int|null, displayName: string} */
    public function author(?User $author): array
    {
        return $author
            ? ['id' => $author->getId(), 'displayName' => $author->getDisplayName()]
            : ['id' => null, 'displayName' => self::DELETED_USER_NAME];
    }
}
