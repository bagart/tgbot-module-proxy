<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Audit;

/**
 * Placement idempotency store (plan §11.18): remembers a job id under a
 * placement key for a TTL window so duplicate placements of the same
 * (tenant, trigger, target set, snapshot) are not created again.
 *
 * The contract is deliberately SET-NX shaped: the winner of the key returns
 * null and may create its job; losers receive the winner's job id and must
 * return that job instead.
 */
interface JobPlacementDedup
{
    /**
     * Atomically claim the key for the given job id. Returns the previously
     * placed job id when the key is still alive, or null when this placement
     * won the key.
     */
    public function place(string $key, string $jobId, int $ttlSeconds): ?string;
}
