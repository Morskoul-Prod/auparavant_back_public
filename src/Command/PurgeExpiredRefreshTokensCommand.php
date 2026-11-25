<?php

namespace App\Command;

use App\Repository\RefreshTokenRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:refresh-tokens:purge',
    description: 'Supprime les refresh tokens expirés (utilisés, révoqués ou non) ; à planifier en cron, par exemple chaque nuit',
)]
class PurgeExpiredRefreshTokensCommand extends Command
{
    public function __construct(private readonly RefreshTokenRepository $refreshTokens)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Compte les jetons concernés sans rien supprimer');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $now = new \DateTimeImmutable();
        $dryRun = (bool) $input->getOption('dry-run');
        $count = $dryRun
            ? $this->refreshTokens->countExpiredBefore($now)
            : $this->refreshTokens->deleteExpiredBefore($now);

        $io->success(sprintf('%d refresh token(s) expiré(s) %s.', $count, $dryRun ? 'à supprimer' : 'supprimé(s)'));

        return Command::SUCCESS;
    }
}
