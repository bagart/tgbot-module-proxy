# T24 — JobStarter (application service)

Source: `../plan.md` §§11.18, 11.27, 11.35 п.7; Stage 5 scope.

## Scope

Application service that starts an audit job: builds the immutable
`AuditPolicySnapshot` from the workspace `ProxyPolicy` + system dictionaries,
persists it (T23), and creates the `ProxyAuditJob` — idempotently within a
TTL window (§11.18 placement idempotency). This is the Scheduler's single
entry point: Scheduler owns WHEN, the JobStarter captures WHAT
(`Scheduler → AuditRequest → очередь → Worker`).

## Dependencies

| Depends on | Reason |
|---|---|
| T23 | `ProxyAuditJob`, `PolicySnapshot` models |
| T08 | `ProxyPolicy` (lifecycle thresholds, quotas, profile) |
| T92 | `JobIdempotencyKey` (dedup key derivation) |
| T02 | TenantContext |

## Plan references

- §11.18: placement idempotency — duplicate job by
  `(tenant_id, trigger, target_set_hash, policy_snapshot_id)` in TTL window
  is not created again; trigger written into every job
- §11.27: Scheduler → AuditRequest(trigger, profile, targets); judge-outage
  profile downgrade is scheduler-side (out of scope here — the starter
  accepts the profile it is given)
- §11.35 п.7: snapshot = probe profile, lifecycle thresholds, judge set,
  targets, governors, retry policy + policy_version. WorkspacePolicy
  (retention/UI/export) is NOT snapshotted

## Classes to create

### `src/Audit/AuditRequest.php`

```php
final readonly class AuditRequest
{
    public function __construct(
        public readonly AuditTrigger $trigger,
        public readonly string $probeProfile,
        public readonly array $accessIds,   // list<non-empty-string> (UUIDs)
        public readonly ?int $requestedBy,
    ) {}
}
```

### `src/Audit/PolicySnapshotBuilder.php`

Builds `AuditPolicySnapshot` (T93 DTO) from `ProxyPolicy` + config
(`config/proxy-operations.php` retry policy/governor defaults). Bumps
`policy_version` per tenant (stored on the latest `PolicySnapshot` row);
identical content → reuse previous snapshot row (content hash), otherwise
new immutable row.

### `src/Audit/JobStarter.php`

```php
final class JobStarter
{
    public function __construct(
        private readonly PolicySnapshotBuilder $snapshots,
        private readonly JobIdempotencyKey $idempotency, // dedup store contract from T92
        private readonly int $placementTtlSeconds,       // from config
    ) {}

    /** @return ProxyAuditJob newly created, or the existing job in the TTL window */
    public function start(AuditRequest $request): ProxyAuditJob
}
```

Behavior:
1. Resolve tenant from TenantContext (fail-closed, INV-006)
2. Compute `target_set_hash` (sorted access ids)
3. Dedup: Redis SET-NX style key from `JobIdempotencyKey` with TTL; on hit,
   load and return the existing job
4. Build + persist snapshot, create job with `status=pending`
5. Return job

Validation: every access id must belong to the tenant — foreign access ids
fail with a domain exception (no tenant leakage, INV-006/§11.22).

## Tests

### `tests/Feature/Audit/JobStarterTest.php`

- Happy path: request → job created pending, snapshot persisted, trigger set
- Idempotent placement: same request twice in TTL → same job returned, no
  second row
- After TTL expiry (mock time/travel) → new job created
- Snapshot reuse: unchanged policy → same policy_version; changed lifecycle
  threshold → new snapshot row + bumped policy_version
- WorkspacePolicy fields (retention/ui flags) absent from serialized snapshot
- Foreign-tenant access id → exception, nothing persisted
- Works inside TenantContext only — no context → failure

## Verify command

```bash
timeout 120 ../../../vendor/bin/pest --filter=JobStarter
```
