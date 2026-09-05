<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Tests\Fixtures;

use BAGArt\ProxyOperations\Audit\JobPlacementDedup;

/**
 * Test double for the placement dedup store: process-local memory instead of
 * the cache store. Not part of the module domain.
 */
final class InMemoryJobPlacementDedup implements JobPlacementDedup
{
    /** @var array<string, string> */
    public array $placed = [];

    public function place(string $key, string $jobId, int $ttlSeconds): ?string
    {
        $existing = $this->placed[$key] ?? null;

        if ($existing !== null) {
            return $existing;
        }

        $this->placed[$key] = $jobId;

        return null;
    }
}
