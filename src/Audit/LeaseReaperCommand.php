<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Audit;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Lease reaper runner (plan §11.24, IMPROVE#9): returns expired active
 * leases to the pool. Safe to schedule frequently — the batch scan is
 * bounded by audit.leases.reaper_batch_size.
 */
#[AsCommand(name: 'proxy-operations:leases-reap', description: 'Reclaim expired proxy leases (crash recovery).')]
final class LeaseReaperCommand extends Command
{
    public function __construct(private readonly LeaseService $leases)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $reaped = $this->leases->reclaimExpired(PHP_INT_MAX);

        $output->writeln("Reaped {$reaped} expired lease(s).");

        return Command::SUCCESS;
    }
}
