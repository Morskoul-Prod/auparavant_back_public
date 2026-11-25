<?php

namespace App\Command;

use App\Repository\QuizGameRepository;
use App\Service\QuizClock;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:quiz:purge-abandoned',
    description: 'Supprime les parties de quiz jamais terminées (in_progress) d\'un jour passé depuis plus de N jours, avec leurs manches',
)]
class PurgeAbandonedQuizGamesCommand extends Command
{
    public function __construct(
        private readonly QuizGameRepository $quizGameRepository,
        private readonly QuizClock $clock,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'Ancienneté minimale de la partie, en jours (jour de Paris)', '30')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Compte les parties concernées sans rien supprimer');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $days = filter_var($input->getOption('days'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($days === false) {
            $io->error('L\'option --days doit être un entier positif ou nul.');

            return Command::INVALID;
        }

        // Les parties terminées ne sont jamais touchées, ni celle d'aujourd'hui (avec --days=0 : tous les jours passés)
        $limit = $this->clock->today()->modify(sprintf('-%d days', $days));
        $dryRun = (bool) $input->getOption('dry-run');
        $count = $dryRun
            ? $this->quizGameRepository->countAbandonedBefore($limit)
            : $this->quizGameRepository->deleteAbandonedBefore($limit);

        $io->success(sprintf(
            '%d partie(s) abandonnée(s) %s (jour antérieur de plus de %d jour(s)).',
            $count,
            $dryRun ? 'à supprimer' : 'supprimée(s)',
            $days
        ));

        return Command::SUCCESS;
    }
}
