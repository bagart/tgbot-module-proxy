<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Domain\Pool;

use Illuminate\Support\Carbon;

/**
 * Outcome of one dynamic materialization run (plan §11.25): reproducible
 * member projection + the run identity stamped on the pool and every
 * accepted/skipped decision row.
 */
final readonly class PoolMaterializationResult
{
    public function __construct(
        public readonly string $materializationId,
        public readonly string $poolId,
        public readonly int $accepted,
        public readonly int $skipped,
        public readonly Carbon $generatedAt,
        public readonly int $policyVersion,
    ) {}
}
