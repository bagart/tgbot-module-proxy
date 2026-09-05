<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Audit;

use BAGArt\ProxyOperations\Domain\Cache\ProbeCacheKeyV3;
use BAGArt\ProxyOperations\Domain\Cache\SharedCacheValue;
use BAGArt\ProxyOperations\Domain\Cache\SharedCacheValueKind;

/**
 * Shared raw-probe cache contract (T29; plan §§11.7, 11.14, R6.2/R6.4).
 * One entry per (ProbeCacheKeyV3 hash, SharedCacheValueKind); values are
 * SharedCacheValue DTOs only — the allowlist/enforcement lives there
 * (INV-005). Raw observations remain the source of truth: a cold or broken
 * cache only costs egress, never correctness.
 */
interface ProbeCache
{
    public function get(ProbeCacheKeyV3 $key, SharedCacheValueKind $kind): ?ProbeCacheEntry;

    public function put(ProbeCacheKeyV3 $key, SharedCacheValue $value): void;

    /**
     * Store a "this probe failed recently" marker (negative caching).
     */
    public function putNegative(ProbeCacheKeyV3 $key, string $failureCode): void;

    /**
     * Failure code of the cached negative marker, or null.
     */
    public function getNegative(ProbeCacheKeyV3 $key): ?string;
}
