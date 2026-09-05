<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Audit;

use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Laravel-cache-backed ProbeCacheMetrics (T30): monotonic counters via
 * Cache::increment under `proxy:probe-cache:metrics:<counter>:<kind>` with a
 * long TTL. Metrics failures never break a probe run — every counter update
 * is best-effort, mirroring LaravelCacheProbeCache.
 */
final class LaravelCacheProbeCacheMetrics implements ProbeCacheMetrics
{
    private const string KEY_PREFIX = 'proxy:probe-cache:metrics:';

    /** Long TTL: metrics are runtime data, not evidence (R6.2). */
    private const int TTL_SECONDS = 7 * 24 * 3600;

    public function hit(string $kind): void
    {
        $this->increment('hit', $kind);
    }

    public function miss(string $kind): void
    {
        $this->increment('miss', $kind);
    }

    public function negativeHit(string $kind): void
    {
        $this->increment('negative_hit', $kind);
    }

    public function stored(string $kind): void
    {
        $this->increment('stored', $kind);
    }

    private function increment(string $counter, string $kind): void
    {
        $key = self::KEY_PREFIX.$counter.':'.$kind;

        try {
            $value = Cache::increment($key);

            // increment() does not set an expiry on a fresh key — seed the
            // long TTL exactly once, on the first increment.
            if ($value === 1) {
                Cache::put($key, 1, self::TTL_SECONDS);
            }
        } catch (Throwable) {
            // Store outage — metrics are never worth a probe failure.
        }
    }
}
