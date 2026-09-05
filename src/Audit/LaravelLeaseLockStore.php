<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Audit;

use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Laravel-cache-backed LeaseLockStore (same facade pattern as
 * CacheJobPlacementDedup): acquire is the atomic Cache::add (SET-NX); renew
 * and release are owner-checked compare-and-swap operations. Store failures
 * surface as acquire=false / renew=false; release never throws — a lost
 * Redis must not break releasing the Postgres state (plan §11.24).
 *
 * Redis loss between operations is tolerated by design: Postgres lease
 * state is reconciled on recovery (§11.24); the lock only guards concurrent
 * acquire.
 */
final class LaravelLeaseLockStore implements LeaseLockStore
{
    private const string KEY_PREFIX = 'proxy:lease:lock:';

    public function acquire(string $lockKey, string $holder, int $ttlMs): bool
    {
        try {
            return Cache::add(self::KEY_PREFIX.$lockKey, $holder, max(1, (int) ceil($ttlMs / 1000)));
        } catch (Throwable) {
            return false;
        }
    }

    public function renew(string $lockKey, string $holder, int $ttlMs): bool
    {
        try {
            $key = self::KEY_PREFIX.$lockKey;
            $current = Cache::get($key);

            if ($current !== $holder) {
                return false;
            }

            Cache::put($key, $holder, max(1, (int) ceil($ttlMs / 1000)));

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function release(string $lockKey, string $holder): void
    {
        try {
            $key = self::KEY_PREFIX.$lockKey;

            if (Cache::get($key) === $holder) {
                Cache::forget($key);
            }
        } catch (Throwable) {
            // State truth lives in Postgres; a failing store must not break
            // the release path (§11.24).
        }
    }
}
