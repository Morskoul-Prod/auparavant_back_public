<?php

namespace App\Command;

use App\Repository\NotificationRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:notifications:purge',
    description: 'Supprime les notifications de commentaires et de modération vues depuis plus de N jours (les non vues sont conservées)',
)]
class PurgeSeenNotificationsCommand extends Command
{
    public function __construct(private readonly NotificationRepository $notificationRepository)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'Ancienneté minimale du « vu », en jours', '30')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Compte les notifications concernées sans rien supprimer');
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
        $dryRun = (bool) $input->getOption('dry-run');
        $count = $dryRun
            ? $this->notificationRepository->countSeenBefore($limit)
            : $this->notificationRepository->deleteSeenBefore($limit);

        $io->success(sprintf(
            '%d notification(s) %s (vues depuis plus de %d jour(s)).',
            $count,
            $dryRun ? 'à supprimer' : 'supprimée(s)',
            $days
        ));

        return Command::SUCCESS;
    }
}
