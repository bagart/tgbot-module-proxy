<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Audit;

/**
 * Concurrency primitive for leases (plan §11.24): an owner-checked Redis
 * lock. All methods are fail-closed on store errors — the Postgres lease
 * state stays the truth; a lost lock never creates or extends a lease.
 */
interface LeaseLockStore
{
    /**
     * Atomic SET-NX style acquisition; false when held by anyone.
     */
    public function acquire(string $lockKey, string $holder, int $ttlMs): bool;

    /**
     * Extend the TTL, only when the holder still owns the lock.
     */
    public function renew(string $lockKey, string $holder, int $ttlMs): bool;

    /**
     * Drop the lock, only when the holder owns it; never throws.
     */
    public function release(string $lockKey, string $holder): void;
}
