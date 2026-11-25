<?php

namespace App\Repository;

use App\Entity\Report;
use App\Entity\User;
use App\Enum\ReportReason;
use App\Enum\ReportStatus;
use App\Enum\ReportTargetType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Report>
 */
class ReportRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Report::class);
    }

    /** Motifs à traiter en priorité (mentions légales) */
    public const PRIORITY_REASONS = [ReportReason::COPYRIGHT, ReportReason::ILLEGAL];

    /**
     * Signalements ouverts (file de traitement) : motifs prioritaires (droit d'auteur, contenu illégal) en premier,
     * puis du plus ancien au plus récent à l'intérieur de chaque groupe.
     *
     * @return Report[]
     */
    public function findOpen(): array
    {
        $reports = $this->createQueryBuilder('r')
            ->addSelect('u')
            ->leftJoin('r.reporter', 'u')
            ->where('r.status = :open')
            ->setParameter('open', ReportStatus::OPEN)
            ->orderBy('r.createdAt', 'ASC')
            ->addOrderBy('r.id', 'ASC')
            ->getQuery()
            ->getResult();

        // Tri stable : l'ordre chronologique de la requête est conservé dans chaque groupe
        usort($reports, fn (Report $a, Report $b) => (int) !in_array($a->getReason(), self::PRIORITY_REASONS, true) <=> (int) !in_array($b->getReason(), self::PRIORITY_REASONS, true));

        return $reports;
    }

    public function countOpen(): int
    {
        return $this->count(['status' => ReportStatus::OPEN]);
    }

    public function findOneByReporterAndTarget(User $reporter, ReportTargetType $type, int $targetId): ?Report
    {
        return $this->findOneBy(['reporter' => $reporter, 'targetType' => $type, 'targetId' => $targetId]);
    }

    /**
     * Signalements ouverts d'une même cible (traités ensemble quand on agit sur la cible)
     *
     * @return Report[]
     */
    public function findOpenByTarget(ReportTargetType $type, int $targetId): array
    {
        return $this->findBy(['targetType' => $type, 'targetId' => $targetId, 'status' => ReportStatus::OPEN]);
    }
}
