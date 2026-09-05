<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Audit;

use BAGArt\ProxyOperations\Domain\Cache\EventEnvelope;
use BAGArt\ProxyOperations\Domain\Lease\LeaseState;
use BAGArt\ProxyOperations\Domain\Lease\ProxyLeaseDto;
use BAGArt\ProxyOperations\Models\ProxyAccess;
use BAGArt\ProxyOperations\Models\ProxyLease;
use Illuminate\Support\Carbon;
use RuntimeException;
use Throwable;

/**
 * Lease lifecycle (plan §11.24): Redis lock (concurrency primitive) →
 * Postgres state (truth) → runtime DTO. One ACTIVE lease per AccessIdentity.
 * Renew is the holder heartbeat; the reaper core is reclaimExpired().
 * Events are recorded strictly after commit (§11.9/§11.35 п.18).
 */
final class LeaseService
{
    private const string EVENT_ACQUIRED = 'lease.acquired';
    private const string EVENT_RELEASED = 'lease.released';

    public function __construct(
        private readonly LeaseLockStore $locks,
        private readonly AuditEventRecorder $events,
        private readonly int $ttlSeconds = 300,
        private readonly int $reaperBatchSize = 200,
    ) {
        if ($this->ttlSeconds < 1) {
            throw new RuntimeException('Lease TTL must be >= 1 second.');
        }
    }

    public function acquire(ProxyAccess $access, string $holder, string $purpose = 'session'): ?ProxyLeaseDto
    {
        $lockKey = self::lockKey($access->id);

        if (! $this->locks->acquire($lockKey, $holder, $this->ttlSeconds * 1000)) {
            // Either concurrently held or the lock store is unavailable —
            // fail-closed: no lock, no lease (§11.24).
            return null;
        }

        $now = Carbon::now();

        try {
            $lease = new ProxyLease([
                'access_id' => $access->id,
                'holder' => $holder,
                'purpose' => $purpose,
                'state' => LeaseState::Active->value,
                'active_marker' => 1,
                'acquired_at' => $now,
                'expires_at' => $now->copy()->addSeconds($this->ttlSeconds),
            ]);
            $lease->save();
        } catch (RuntimeException) {
            // Concurrent active lease (unique access_id+active_marker) or a
            // DB failure — release the lock so nobody is blocked forever.
            $this->locks->release($lockKey, $holder);

            return null;
        }

        $dto = self::toDto($lease);

        $this->events->record(new EventEnvelope(
            eventId: EventEnvelope::generateId(),
            eventType: self::EVENT_ACQUIRED,
            occurredAt: $now->toIso8601String(),
            tenantId: (string) $access->tenant_id,
            aggregateRef: $lease->id,
            payload: [
                'accessId' => $access->id,
                'holder' => $holder,
                'purpose' => $purpose,
                'expiresAtMs' => $dto->expiresAtMs,
            ],
        ));

        return $dto;
    }

    public function renew(ProxyLeaseDto $lease): ?ProxyLeaseDto
    {
        $row = ProxyLease::query()->find($lease->leaseId);

        if ($row === null
            || $row->tenant_id !== (int) $lease->tenantId
            || $row->holder !== $lease->holder
            || $row->state !== LeaseState::Active
            || $row->expires_at->isPast()
        ) {
            return null; // expired leases are reaper territory
        }

        $lockKey = self::lockKey($row->access_id);

        if (! $this->locks->renew($lockKey, $lease->holder, $this->ttlSeconds * 1000)) {
            return null; // fail-closed: cannot hold the lock, cannot extend
        }

        $now = Carbon::now();
        $row->forceFill([
            'expires_at' => $now->copy()->addSeconds($this->ttlSeconds),
            'renewals' => $row->renewals + 1,
        ])->save();

        return self::toDto($row);
    }

    public function release(ProxyLeaseDto $lease): void
    {
        $row = ProxyLease::query()->find($lease->leaseId);

        if ($row === null
            || $row->tenant_id !== (int) $lease->tenantId
            || $row->holder !== $lease->holder
            || $row->state !== LeaseState::Active
        ) {
            return;
        }

        // Postgres state FIRST — the truth survives any Redis loss (§11.24).
        $now = Carbon::now();
        $row->forceFill([
            'state' => LeaseState::Released->value,
            'active_marker' => null,
            'released_at' => $now,
        ])->save();

        try {
            $this->locks->release(self::lockKey($row->access_id), $lease->holder);
        } catch (Throwable) {
            // A broken lock store must never block the release of state.
        }

        $this->events->record(new EventEnvelope(
            eventId: EventEnvelope::generateId(),
            eventType: self::EVENT_RELEASED,
            occurredAt: $now->toIso8601String(),
            tenantId: (string) $row->tenant_id,
            aggregateRef: $row->id,
            payload: [
                'accessId' => $row->access_id,
                'holder' => $row->holder,
                'purpose' => $row->purpose,
            ],
        ));
    }

    /**
     * Reaper core (§11.24 / IMPROVE#9): return expired active leases to the
     * pool. System-level scan (not tenant-scoped by design — scheduled job);
     * Redis locks are dropped best-effort.
     */
    public function reclaimExpired(int $limit): int
    {
        $now = Carbon::now();

        $expired = ProxyLease::query()
            ->withoutGlobalScopes()
            ->where('state', LeaseState::Active->value)
            ->where('expires_at', '<', $now)
            ->orderBy('expires_at')
            ->limit(min($limit, $this->reaperBatchSize))
            ->get();

        foreach ($expired as $lease) {
            $lease->forceFill([
                'state' => LeaseState::Expired->value,
                'active_marker' => null,
            ])->save();

            try {
                $this->locks->release(self::lockKey($lease->access_id), $lease->holder);
            } catch (Throwable) {
                // Best-effort: state is already expired in Postgres.
            }
        }

        return $expired->count();
    }

    private static function lockKey(string $accessId): string
    {
        return $accessId;
    }

    private static function toDto(ProxyLease $lease): ProxyLeaseDto
    {
        return new ProxyLeaseDto(
            leaseId: $lease->id,
            accessId: $lease->access_id,
            tenantId: (string) $lease->tenant_id,
            holder: $lease->holder,
            purpose: $lease->purpose,
            acquiredAtMs: (int) $lease->acquired_at->getTimestampMs(),
            expiresAtMs: (int) $lease->expires_at->getTimestampMs(),
        );
    }
}
