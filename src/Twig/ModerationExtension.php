<?php

namespace App\Twig;

use App\Repository\PictureRepository;
use App\Repository\ReportRepository;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Expose au back-office le nombre de photos à modérer et de signalements ouverts (pastilles de la barre latérale)
 */
class ModerationExtension extends AbstractExtension
{
    public function __construct(
        private readonly PictureRepository $pictureRepository,
        private readonly ReportRepository $reportRepository,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('pending_moderation_count', $this->pictureRepository->countPending(...)),
            new TwigFunction('open_reports_count', $this->reportRepository->countOpen(...)),
        ];
    }
}
