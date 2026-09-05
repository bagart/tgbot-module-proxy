# T33 — Dynamic pool materializer + decision log

Source: `../plan.md` §11.25 (pool projection, decision log), §11.20
(`PoolRebuilt` domain event).
Depends on: T32 (pools + members), T28 (event recorder), T27 (health data).

## Scope

Rebuild a DYNAMIC/HYBRID pool from its predicate: candidate query →
per-candidate decision → replace member projection atomically. Every
decision (accept or skip, with reason) lands in the decision log —
"why skipped" must be answerable without re-running the selector
(§11.25). Materialization is reproducible: same inputs + same policy
version → same members.

## Plan references

- §11.25: dynamic pool = projection with `materialization_version`,
  `generated_at`, `decision_log_id`; PoolMember is not a second source of truth
- §11.25: decision log entry `{candidate, predicate, decision, reason_code,
  score, policy_version, timestamp}` — no credentials ever
- §11.20/§11.15: `PoolRebuilt` is a domain event (envelope V1, outbox via
  T28's `AuditEventRecorder`)

## Migrations

### `create_proxy_pool_decisions_table`

Append-only decision log (tenant-scoped):

- `id`, `tenant_id`, `pool_id`, `materialization_id` (ulid grouping one run)
- `access_id` (nullable — for pool-level failures e.g. predicate invalid)
- `decision` (string: `accepted|skipped`), `reason_code` (string)
- `score` (nullable float — candidate score at decision time)
- `policy_version` (int), `created_at`
- index `(tenant_id, pool_id, materialization_id)`; no updates ever
  (documented: rows immutable)

Add to `proxy_pools`: `last_materialization_id` (nullable string),
`last_materialized_at` (nullable timestamp) — additive migration.

## Classes

### `src/Domain/Pool/PoolMaterializationResult.php`

Readonly DTO: materializationId, poolId, accepted count, skipped count,
generatedAtMs, policyVersion.

### `src/Domain/Pool/SelectionReasonCode.php`

Enum for reason codes (additive): `PredicateStateMismatch`,
`PredicateProtocolMismatch`, `PredicateTagMismatch`,
`PredicateHealthBelowFloor`, `PredicateTelegramFilter`,
`PredicateCountryMismatch`, `NotEligible`, `AlreadyMember`,
`DuplicateCandidate`, `PredicateInvalid`. TitleCase, string backed with
snake_case values as listed.

### `src/Audit/PoolMaterializer.php`

```php
final class PoolMaterializer
{
    public function __construct(
        private readonly VerifiedEligibilityPolicy $eligibility, // T05/T27 evidence
        private readonly AuditEventRecorder $events,             // T28 outbox
    ) {}

    public function materialize(ProxyPool $pool, int $policyVersion): PoolMaterializationResult;
}
```

Semantics:

- STATIC pools → domain exception (nothing to materialize)
- candidate query: all accesses of the pool's tenant, joined with health;
  per candidate build `PoolCandidateView` → predicate `matches()` →
  on mismatch: `skipped` + specific reason code
- eligible candidates additionally pass `VerifiedEligibilityPolicy`
  (evidence-based; failure → `skipped` / `NotEligible`)
- accepted set replaces the pool's dynamic members in ONE DB transaction:
  delete rows where `materialization_version` was materializer-owned
  (preserve hand-picked static members of HYBRID pools — rows with
  `materialization_version IS NULL` survive), insert new rows with the new
  `materialization_version`; decision rows written in the same transaction
- after commit: `PoolRebuilt` event recorded via `AuditEventRecorder`
  (aggregate = pool id, payload = counts + materialization id — no member
  lists, no credentials)
- guard: candidate set cap from config (`audit.pools.max_members`, default
  10000) — exceeding → abort with `PredicateInvalid`-style failure, no
  partial materialization

### Config

`config/proxy-operations.php`: new `audit.pools` section:
`max_members` (int, default 10000).

## Tests

### `tests/Feature/Pools/PoolMaterializerTest.php`

- DYNAMIC pool: candidates split accepted/skipped with correct reason codes
  (one test per predicate dimension)
- Reproducibility: same data + same policy version → two runs produce
  identical member sets and distinct materialization ids
- HYBRID: hand-picked member (materialization_version NULL) survives
  rebuild; dynamic members replaced
- Version bump: members carry the new materialization_version
- `PoolRebuilt` recorded after commit (capturing recorder); NOTHING recorded
  when materialization aborted (cap exceeded → exception + untouched members)
- Decision log rows immutable-shaped: accepted AND skipped both persisted
  with scores/policy version; no credential material anywhere in log rows
  (assert against the credential columns if joined)

### Tenancy (in the same file or `PoolMaterializerTenancyTest`)

- Candidate query never crosses tenant even with foreign access rows present
- Event payload carries pool tenant_id matching the pool

## Verify command

```bash
timeout 180 ../../../vendor/bin/pest --filter=PoolMaterializer
```
