<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Transport;

use BAGArt\AsyncKernel\Contracts\Daemons\ASKTickableContract;
use BAGArt\ProxyOperations\Domain\Lease\LeaseRenewerContract;

/**
 * Renews proxy access leases during long-running probe executions (plan
 * §11.24). The daemon tracks which access IDs are currently being probed
 * and delegates renewal to this tickable, which runs alongside the daemon
 * via WithASKTickableContract::tickable().
 *
 * Without this renewer, a probe taking longer than the lease TTL (300s
 * default) would have its lease reclaimed by the reaper while still in
 * flight, causing another consumer to acquire the same proxy.
 */
final class ProbeLeaseRenewer implements ASKTickableContract
{
    /** @var array<string, array{lastRenewedAt: int}> */
    private array $tracked = [];

    public function __construct(
        private readonly LeaseRenewerContract $leases,
        private readonly int $renewIntervalSec = 60,
    ) {}

    /**
     * Track an access ID whose lease should be renewed while probing.
     */
    public function track(string $accessId): void
    {
        if (! isset($this->tracked[$accessId])) {
            $this->tracked[$accessId] = [
                'lastRenewedAt' => 0,
            ];
        }
    }

    /**
     * Stop tracking an access ID (probe completed).
     */
    public function untrack(string $accessId): void
    {
        unset($this->tracked[$accessId]);
    }

    public function tick(int $systemPressure): void
    {
        $now = time();

        foreach ($this->tracked as $accessId => &$entry) {
            if (($now - $entry['lastRenewedAt']) < $this->renewIntervalSec) {
                continue;
            }

            $this->leases->renewByAccessId($accessId);
            $entry['lastRenewedAt'] = $now;
        }
        unset($entry);
    }

    public function pressure(): int
    {
        $count = count($this->tracked);

        return $count > 0 ? (int) round(($count / 100) * 100) : 0;
    }

    public function isIdle(): bool
    {
        return $this->tracked === [];
    }

    public function queueSize(): int
    {
        return count($this->tracked);
    }
}
