<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Audit;

/**
 * Hit/miss counters for the shared raw-probe cache (T30; plan §11.7).
 * Operational runtime data only — counters live in the cache store
 * (Redis in production), never in Postgres (INV-009).
 */
interface ProbeCacheMetrics
{
    public function hit(string $kind): void;

    public function miss(string $kind): void;

    public function negativeHit(string $kind): void;

    public function stored(string $kind): void;
}
