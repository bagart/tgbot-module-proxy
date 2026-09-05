# T35 — Selector + decision log + lease reaper

Source: `../plan.md` §11.25 (selection flow), §11.24 (reaper recovery),
§10.12 п.9 (selector API).
Depends on: T32 (pools), T33 (materializer + decision log), T34 (leases),
T27 (health/freshness), T05 (VerifiedEligibilityPolicy).

## Scope

The selection engine: `SelectionCriteria → candidate query (pool predicate)
→ freshness gate → reevaluate → ProxyLease`, with per-candidate decision-log
entries answering "why skipped" (§11.25). Plus the lease-reaper runner
(IMPROVE#9) that returns expired leases to the pool — the crash-recovery
half of §11.24.

## Plan references

- §11.25 flow: the selector performs NO network checks except the permitted
  lazy-check, which is an OUT-OF-PROCESS request (light audit job via the
  existing JobStarter T24) — selection itself never blocks on probes
- §10.12 п.9: `ProxySelectorContract::acquire(criteria)` for platform
  modules; the plan sketches a Promise return — this MVP is synchronous
  (`?ProxyLeaseDto`); the async/Fiber wrapper belongs to the Gateway phase
  and is NOT built here. Report as a declared deviation in the README.
- §11.24: recovery = reaper returns expired leases; Postgres is the
  reconciliation truth
- §11.20: `LeaseAcquired`/`LeaseReleased` already exist (T34)

## Classes

### `src/Domain/Lease/SelectionCriteria.php`

Readonly DTO (JsonSerializable + SCHEMA_VERSION + fromJsonV1):
poolId (nullable), purpose, count (default 1), holder, requiredProtocol,
requiredCountry, telegramUsableOnly, excludeAccessIds (list), maxStalenessSeconds
(nullable — freshness gate; null = no gate).

### `src/Domain/Lease/SelectionDecision.php`

Readonly log entry DTO: accessId, decision (`selected|skipped`),
reasonCode (reuse `SelectionReasonCode` from T33, add
`StaleHealth`, `LeaseUnavailable`, `Excluded`, `PoolEmpty` cases
additively), score (nullable), policyVersion, createdAtMs.

### `src/Domain/Lease/SelectionStrategy.php` (interface)

```php
interface SelectionStrategy
{
    /** @param list<ScoredCandidate> $candidates @return list<ScoredCandidate> selection order */
    public function order(array $candidates): array;
}
```

Implementations (`final readonly`): `RoundRobinSelectionStrategy`
(state = per-pool cursor persisted in the cache store — survives processes),
`RandomSelectionStrategy`, `LeastUsedSelectionStrategy` (fewest active
leases + recent release count from `proxy_leases`), `WeightedSelectionStrategy`
(weight = health score × freshness factor). `ScoredCandidate` readonly DTO
(access id, score, staleness).

Strategy choice from config `audit.selection.strategy` (`round_robin`
default); `PoolPredicate`-driven diversity (geo/IP anti-correlation) is
OUT of scope here (Phase B/C UX work, plan §5) — single-criteria MVP.

### `src/Audit/ProxySelector.php`

```php
final class ProxySelector
{
    public function __construct(
        private readonly SelectionStrategy $strategy,
        private readonly LeaseService $leases,           // T34
        private readonly VerifiedEligibilityPolicy $eligibility,
        private readonly JobStarter $jobStarter,         // T24 — lazy-check trigger
        private readonly AuditEventRecorder $events,     // T28 (decision-log audit trail is DB; events optional)
    ) {}

    /** @return list<ProxyLeaseDto> */
    public function acquire(string $tenantId, SelectionCriteria $criteria): array;
}
```

Flow per §11.25:

1. Resolve pool (tenant-scoped; criteria poolId or the tenant's default
   dynamic pool → none found → empty result + `PoolEmpty` log entry).
2. Candidate query: pool members joined to access+health; apply
   criteria hard filters (protocol/country/telegram/exclusions) → skipped
   entries with the specific reason codes.
3. Freshness gate: `maxStalenessSeconds` vs health `last_checked_at` →
   stale candidates: trigger a light audit job via `JobStarter` (one per
   stale access, trigger `selection_lazy_check`) and SKIP them this round
   (`StaleHealth`); they become selectable after the audit completes
   (next acquire). The selector never runs probes inline.
4. Eligibility check per candidate (`NotEligible` skips).
5. Order by strategy; take `count`; for each try `LeaseService::acquire`;
   acquisition failure → `LeaseUnavailable` skip, continue to next.
6. Persist ALL decision entries (T33's `proxy_pool_decisions` table, reuse —
   add nothing new; `materialization_id` column stores a selection run id).
7. Return acquired leases (may be fewer than requested — never block,
   never partially fail).

### `src/Audit/LeaseReaperTick.php`

Tickable-style runner (match the module's existing tick/daemon patterns;
if none, an invokable class + Artisan command):

- `proxy-operations:leases-reap` — calls `LeaseService::reclaimExpired`
  with the configured batch size; logs counts; safe to run on a schedule
- crash-recovery guarantee under test: consumer "dies" (lease row active,
  Redis lock present or absent) → reaper expires → access acquirable by a
  new holder; Postgres state is authoritative (Redis may be flushed
  entirely between crash and reap — behavior identical)

### Config

`config/proxy-operations.php`: new `audit.selection` section:
`strategy` ('round_robin'), `default_max_staleness_seconds` (3600),
`lazy_check_probe_profile` (string key into the existing
`audit.probe_profile_mapping`).

## Tests

### `tests/Feature/Selection/ProxySelectorTest.php`

- Happy path: pool with 3 healthy members, criteria count=2 → 2 leases,
  decision log has 2 `selected` entries
- Each hard-filter dimension produces its reason code (protocol, country,
  telegram, exclusion)
- Staleness: stale candidate → skipped `StaleHealth` AND a light audit job
  was started (assert via JobStarter fake/captured jobs); fresh candidate
  selected
- Ineligible candidate → `NotEligible`; candidate whose lease acquire fails
  → `LeaseUnavailable` and the next candidate is used (count still met)
- Fewer eligible than requested → partial result, no error
- Empty pool → empty result + `PoolEmpty`, no leases touched
- Strategy behaviors: round_robin rotates across repeated acquires;
  weighted prefers higher score; least_used prefers never-leased member
- Tenancy: foreign-tenant pool id → empty result; no cross-tenant members
  ever leased

### `tests/Feature/Selection/LeaseReaperTest.php`

- Active lease with expired `expires_at` → reaper → `expired`, acquirable
  again (crash recovery)
- Redis flushed mid-scenario → reap + reacquire still work (Postgres truth)
- Fresh (non-expired) leases untouched; released leases untouched
- Reaper command runs and reports the count (Artisan test)

## Verify command

```bash
timeout 180 ../../../vendor/bin/pest --filter=ProxySelector
timeout 120 ../../../vendor/bin/pest --filter=LeaseReaper
timeout 600 ../../../vendor/bin/pest
```
