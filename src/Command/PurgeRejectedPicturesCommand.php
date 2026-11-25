<?php

namespace App\Command;

use App\Repository\PictureRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:pictures:purge-rejected',
    description: 'Supprime (fichier et ligne) les photos refusées depuis plus de N jours, vues ou non',
)]
class PurgeRejectedPicturesCommand extends Command
{
    public function __construct(
        private readonly PictureRepository $pictureRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'Ancienneté minimale du refus, en jours', '30')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Liste les fichiers concernés sans rien supprimer');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $days = filter_var($input->getOption('days'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($days === false) {
            $io->error('L\'option --days doit être un entier positif ou nul.');

            return Command::INVALID;
        }

        $limit = new \DateTimeImmutable(sprintf('-%d days', $days));
        $pictures = $this->pictureRepository->findRejectedBefore($limit);
        $dryRun = (bool) $input->getOption('dry-run');

        foreach ($pictures as $picture) {
            $io->writeln(sprintf('Photo #%d (%s)', $picture->getId(), $picture->getImageName() ?? 'fichier déjà supprimé'), OutputInterface::VERBOSITY_VERBOSE);
            if (!$dryRun) {
                // Vich supprime le fichier avec l'entité (sans erreur s'il est déjà absent)
                $this->entityManager->remove($picture);
            }
        }

        if (!$dryRun) {
            $this->entityManager->flush();
        }

        $io->success(sprintf(
            '%d photo(s) %s (refus de plus de %d jour(s)).',
            count($pictures),
            $dryRun ? 'à supprimer' : 'supprimée(s)',
            $days
        ));

        return Command::SUCCESS;
    }
}
