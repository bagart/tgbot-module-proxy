<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Audit;

use BAGArt\ProxyOperations\Domain\Cache\ProbeCacheKeyV3;
use BAGArt\ProxyOperations\Domain\Cache\SharedCacheValue;
use BAGArt\ProxyOperations\Domain\Cache\SharedCacheValueKind;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Laravel cache-backed ProbeCache (T29; plan §11.7): one entry per
 * (ProbeCacheKeyV3 hash, kind), stored as ProbeCacheEntry JSON with the
 * CachePolicy TTL. Same lazy pattern as CacheJobPlacementDedup — the store
 * is only touched from within method calls (INV-009: lazy connection).
 *
 * The cache must never break a probe run: every store failure is swallowed
 * and treated as a miss (raw observations remain the source of truth; a
 * cold cache only costs egress). A disabled policy short-circuits without
 * touching the store at all.
 */
final class LaravelCacheProbeCache implements ProbeCache
{
    public function __construct(
        private readonly CachePolicy $policy,
    ) {}

    public function get(ProbeCacheKeyV3 $key, SharedCacheValueKind $kind): ?ProbeCacheEntry
    {
        if (! $this->policy->enabled) {
            return null;
        }

        try {
            $raw = Cache::get($this->cacheKey($key, $kind));
        } catch (Throwable) {
            return null;
        }

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        try {
            /** @var array<string,mixed> $data */
            $data = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);

            return ProbeCacheEntry::fromJson($data);
        } catch (Throwable) {
            // Corrupt payload or unsupported schema version — treat as a miss.
            return null;
        }
    }

    public function put(ProbeCacheKeyV3 $key, SharedCacheValue $value): void
    {
        $this->putEntry($key, $value, $this->policy->ttlForKind($value->kind));
    }

    public function putNegative(ProbeCacheKeyV3 $key, string $failureCode): void
    {
        $value = new SharedCacheValue(
            kind: SharedCacheValueKind::NegativeProbeResult,
            payload: [
                'failure_code' => $failureCode,
                'checked_at_ms' => Carbon::now()->getTimestampMs(),
            ],
        );

        $this->putEntry($key, $value, $this->policy->negativeTtlSeconds);
    }

    public function getNegative(ProbeCacheKeyV3 $key): ?string
    {
        $entry = $this->get($key, SharedCacheValueKind::NegativeProbeResult);

        $failureCode = $entry?->value->payload['failure_code'] ?? null;

        return is_string($failureCode) && $failureCode !== '' ? $failureCode : null;
    }

    private function putEntry(ProbeCacheKeyV3 $key, SharedCacheValue $value, int $ttlSeconds): void
    {
        if (! $this->policy->enabled || $ttlSeconds <= 0) {
            return;
        }

        $entry = new ProbeCacheEntry(
            value: $value,
            cachedAtMs: Carbon::now()->getTimestampMs(),
            ageSeconds: 0,
        );

        try {
            Cache::put($this->cacheKey($key, $value->kind), json_encode($entry, JSON_THROW_ON_ERROR), $ttlSeconds);
        } catch (Throwable) {
            // Store outage — never break a probe run because of the cache.
        }
    }

    private function cacheKey(ProbeCacheKeyV3 $key, SharedCacheValueKind $kind): string
    {
        return $this->policy->keyPrefix.$key->toHash().':'.$kind->value;
    }
}
