<?php

namespace App\Repository;

use App\Entity\Comment;
use App\Entity\Memory;
use App\Entity\User;
use App\Enum\CommentStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Comment>
 */
class CommentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Comment::class);
    }

    /**
     * Racines à afficher pour un souvenir : visibles et non supprimées, ou (supprimées ou masquées) mais
     * portant au moins une réponse visible. Du plus récent au plus ancien.
     */
    private function displayableRootsQuery(Memory $memory): QueryBuilder
    {
        return $this->createQueryBuilder('c')
            ->where('c.memory = :memory')
            ->andWhere('c.parent IS NULL')
            ->andWhere('(c.status = :visible AND c.deletedAt IS NULL) OR EXISTS (
                SELECT r.id FROM ' . Comment::class . ' r WHERE r.parent = c AND r.status = :visible
            )')
            ->setParameter('memory', $memory)
            ->setParameter('visible', CommentStatus::VISIBLE);
    }

    /**
     * Page de racines affichables, réponses visibles (ordre chronologique) et auteurs chargés d'un coup.
     * Les réponses sont fixées sur chaque racine via la collection `replies` déjà initialisée.
     *
     * @return Comment[]
     */
    public function findRootsPage(Memory $memory, int $page, int $limit): array
    {
        $roots = $this->displayableRootsQuery($memory)
            ->leftJoin('c.author', 'a')->addSelect('a')
            ->orderBy('c.createdAt', 'DESC')
            ->addOrderBy('c.id', 'DESC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        $this->loadVisibleReplies($roots);

        return $roots;
    }

    public function countDisplayableRoots(Memory $memory): int
    {
        return (int) $this->displayableRootsQuery($memory)
            ->select('COUNT(c.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Commentaires publics (racines et réponses, hors supprimés et masqués) d'un souvenir
     */
    public function countPublic(Memory $memory): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('c.memory = :memory')
            ->andWhere('c.status = :visible')
            ->andWhere('c.deletedAt IS NULL')
            ->setParameter('memory', $memory)
            ->setParameter('visible', CommentStatus::VISIBLE)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Commentaires publics (racines et réponses, hors supprimés et masqués) de plusieurs souvenirs, en une requête
     *
     * @param int[] $memoryIds
     * @return array<int, int> id du souvenir => nombre de commentaires (absent si aucun)
     */
    public function countPublicByMemories(array $memoryIds): array
    {
        if ($memoryIds === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('c')
            ->select('IDENTITY(c.memory) AS memoryId', 'COUNT(c.id) AS total')
            ->where('c.memory IN (:ids)')
            ->andWhere('c.status = :visible')
            ->andWhere('c.deletedAt IS NULL')
            ->groupBy('c.memory')
            ->setParameter('ids', $memoryIds)
            ->setParameter('visible', CommentStatus::VISIBLE)
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['memoryId']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * Charge en une requête les réponses visibles des racines et les place dans leur collection `replies`
     * (qui ne contient alors que les réponses visibles : ne pas la modifier ensuite).
     *
     * @param Comment[] $roots
     */
    private function loadVisibleReplies(array $roots): void
    {
        if (!$roots) {
            return;
        }

        $replies = $this->createQueryBuilder('r')
            ->leftJoin('r.author', 'a')->addSelect('a')
            ->where('r.parent IN (:roots)')
            ->andWhere('r.status = :visible')
            ->setParameter('roots', $roots)
            ->setParameter('visible', CommentStatus::VISIBLE)
            ->orderBy('r.createdAt', 'ASC')
            ->addOrderBy('r.id', 'ASC')
            ->getQuery()
            ->getResult();

        $byParent = [];
        foreach ($replies as $reply) {
            $byParent[$reply->getParent()->getId()][] = $reply;
        }
        foreach ($roots as $root) {
            $collection = $root->getReplies();
            $collection->clear();
            foreach ($byParent[$root->getId()] ?? [] as $reply) {
                $collection->add($reply);
            }
        }
    }

    /**
     * Réponses (toutes, quel que soit leur statut) d'un commentaire racine
     */
    public function countReplies(Comment $root): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('c.parent = :root')
            ->setParameter('root', $root)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Commentaires et réponses visibles d'un utilisateur, du plus récent au plus ancien (paginés, souvenir chargé).
     * Les commentaires masqués par la modération n'en font pas partie.
     */
    private function authoredQuery(User $author): QueryBuilder
    {
        return $this->createQueryBuilder('c')
            ->where('c.author = :author')
            ->andWhere('c.status = :visible')
            ->setParameter('author', $author)
            ->setParameter('visible', CommentStatus::VISIBLE);
    }

    /**
     * @return Comment[]
     */
    public function findByAuthor(User $author, int $page, int $limit): array
    {
        return $this->authoredQuery($author)
            ->addSelect('m')
            ->join('c.memory', 'm')
            ->orderBy('c.createdAt', 'DESC')
            ->addOrderBy('c.id', 'DESC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countByAuthor(User $author): int
    {
        return (int) $this->authoredQuery($author)
            ->select('COUNT(c.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Commentaires masqués par la modération (hors ceux vidés par une suppression), du masquage le plus récent au
     * plus ancien ; auteur, modérateur et souvenir chargés d'un coup.
     *
     * @return Comment[]
     */
    public function findHidden(int $page, int $limit): array
    {
        return $this->hiddenQuery()
            ->addSelect('a', 'h', 'm')
            ->leftJoin('c.author', 'a')
            ->leftJoin('c.hiddenBy', 'h')
            ->join('c.memory', 'm')
            ->orderBy('c.hiddenAt', 'DESC')
            ->addOrderBy('c.id', 'DESC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countHidden(): int
    {
        return (int) $this->hiddenQuery()->select('COUNT(c.id)')->getQuery()->getSingleScalarResult();
    }

    private function hiddenQuery(): QueryBuilder
    {
        return $this->createQueryBuilder('c')
            ->where('c.status = :hidden')
            ->andWhere('c.deletedAt IS NULL')
            ->setParameter('hidden', CommentStatus::HIDDEN);
    }
}
