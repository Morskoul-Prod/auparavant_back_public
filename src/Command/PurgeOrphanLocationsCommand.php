<?php

namespace App\Command;

use App\Repository\LocationRepository;
use App\Service\OrphanLocationCleaner;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:locations:purge-orphans',
    description: 'Supprime les localisations (et leurs lieux) qui n\'ont plus aucun souvenir',
)]
class PurgeOrphanLocationsCommand extends Command
{
    public function __construct(
        private readonly LocationRepository $locationRepository,
        private readonly OrphanLocationCleaner $orphanLocationCleaner,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Liste les localisations concernées sans rien supprimer');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $locations = $this->locationRepository->findOrphans();
        $dryRun = (bool) $input->getOption('dry-run');

        foreach ($locations as $location) {
            $io->writeln(sprintf(
                'Localisation #%d (%s, %s %s)',
                $location->getId(),
                $location->getStreet(),
                $location->getZipcode(),
                $location->getCity()
            ), OutputInterface::VERBOSITY_VERBOSE);
            if (!$dryRun) {
                $this->orphanLocationCleaner->removeLocation($location);
            }
        }

        if (!$dryRun) {
            $this->entityManager->flush();
        }

        $io->success(sprintf(
            '%d localisation(s) %s.',
            count($locations),
            $dryRun ? 'à supprimer' : 'supprimée(s)'
        ));

        return Command::SUCCESS;
    }
}
