<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Audit;

use Illuminate\Console\Command;

/**
 * Lease reaper runner (plan §11.24, IMPROVE#9): returns expired active
 * leases to the pool. Safe to schedule frequently — the batch scan is
 * bounded by audit.leases.reaper_batch_size.
 */
final class LeaseReaperCommand extends Command
{
    protected $signature = 'proxy:lease:reap';

    protected $description = 'Reclaim expired proxy leases (crash recovery).';

    public function __construct(private readonly LeaseService $leases)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $reaped = $this->leases->reclaimExpired(PHP_INT_MAX);

        $this->line("Reaped {$reaped} expired lease(s).");

        return self::SUCCESS;
    }
}
