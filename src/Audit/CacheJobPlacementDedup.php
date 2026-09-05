<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Audit;

use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Laravel cache()-backed JobPlacementDedup (plan §11.18): the TTL window lives
 * in the cache store (Redis in production, array in tests). Cache::add is the
 * atomic SET-NX primitive — the first placement wins, concurrent losers read
 * back the winner's job id.
 */
final class CacheJobPlacementDedup implements JobPlacementDedup
{
    public function place(string $key, string $jobId, int $ttlSeconds): ?string
    {
        $placed = Cache::add($key, $jobId, now()->addSeconds($ttlSeconds));

        if ($placed) {
            return null;
        }

        $existing = Cache::get($key);

        // The key was claimed between add() and get() — the store must still
        // know the winner; anything else is a cache inconsistency.
        if (! is_string($existing) || $existing === '') {
            throw new RuntimeException('Job placement key was claimed but the stored job id is unreadable.');
        }

        return $existing;
    }
}
