<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Audit;

use BAGArt\ProxyOperations\Domain\Cache\JobIdempotencyKey;
use BAGArt\ProxyOperations\Models\AuditJobStatus;
use BAGArt\ProxyOperations\Models\ProxyAccess;
use BAGArt\ProxyOperations\Models\ProxyAuditJob;
use BAGArt\ProxyOperations\Tenancy\TenantContext;
use BAGArt\ProxyOperations\Tenancy\TenantNotResolvedException;
use Illuminate\Support\Str;

/**
 * Application service that starts an audit job (plan §11.18): the Scheduler's
 * single entry point. Captures WHAT — snapshot (probe profile, lifecycle
 * thresholds, quarantine rules) + target set hash — while the Scheduler owns
 * WHEN. The trigger is written into every job.
 *
 * Placement idempotency (§11.18): a duplicate of
 * (tenant, trigger, target_set_hash, policy_snapshot_id) inside the TTL
 * window is not created again — the SET-NX dedup key returns the existing
 * job. TTL semantics live in the cache store, deliberately without a DB
 * unique constraint.
 *
 * INV-006: the tenant must be resolved in the current scope (fail closed)
 * and every requested access id must belong to that tenant (§11.22 — no
 * tenant leakage); a foreign access id fails with nothing persisted.
 */
final class JobStarter
{
    public function __construct(
        private readonly PolicySnapshotBuilder $snapshots,
        private readonly JobPlacementDedup $placement,
        private readonly TenantContext $tenant,
        private readonly int $placementTtlSeconds, // from config
    ) {}

    /**
     * @return ProxyAuditJob Newly created, or the existing job in the TTL window.
     *
     * @throws TenantNotResolvedException When no tenant is set in this scope.
     * @throws ForeignAccessIdException When any access id belongs to another tenant.
     */
    public function start(AuditRequest $request): ProxyAuditJob
    {
        $tenantId = $this->tenant->id(); // fail-closed, INV-006

        $this->assertAccessIdsBelongToTenant($request->accessIds);

        $targetSetHash = self::targetSetHash($request->accessIds);
        $snapshot = $this->snapshots->build($request->trigger, $request->probeProfile);

        $key = JobIdempotencyKey::fromPlacement(
            tenantId: $tenantId,
            trigger: $request->trigger->value,
            targetSetHash: $targetSetHash,
            policySnapshotId: $snapshot->id,
        )->value;

        $jobId = (string) Str::uuid();

        $existingJobId = $this->placement->place($key, $jobId, $this->placementTtlSeconds);

        if ($existingJobId !== null) {
            return ProxyAuditJob::query()->findOrFail($existingJobId);
        }

        // forceFill: the dedup key was claimed for this exact id, so the row
        // id must match what the placement store remembers.
        $job = new ProxyAuditJob;
        $job->forceFill([
            'id' => $jobId,
            'trigger' => $request->trigger,
            'policy_snapshot_id' => $snapshot->id,
            'requested_by' => $request->requestedBy,
            'target_set_hash' => $targetSetHash,
            'status' => AuditJobStatus::Pending,
        ]);
        $job->save();

        return $job;
    }

    /**
     * Identity of the requested target set (sorted access ids — placement
     * order must not change identity). Foreign ids were rejected before this
     * point, so the hash never crosses tenants.
     *
     * @param  list<non-empty-string>  $accessIds
     */
    private static function targetSetHash(array $accessIds): string
    {
        $sorted = array_values(array_unique($accessIds));
        sort($sorted);

        return hash('sha256', implode("\x00", $sorted));
    }

    /**
     * @param  list<non-empty-string>  $accessIds
     *
     * @throws ForeignAccessIdException When any id is unknown to this tenant.
     */
    private function assertAccessIdsBelongToTenant(array $accessIds): void
    {
        // The BelongsToTenant global scope auto-filters by TenantContext
        // (INV-006), so any unknown id here belongs to another tenant (or
        // does not exist at all — same treatment: fail loudly, persist nothing).
        $unique = array_values(array_unique($accessIds));

        $ownedCount = ProxyAccess::query()
            ->whereKey($unique)
            ->count();

        if (count($unique) !== $ownedCount) {
            throw new ForeignAccessIdException(
                'Audit request references access ids outside the current tenant.',
            );
        }
    }
}
