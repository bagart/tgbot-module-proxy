# T30 — Cache-aware probe planner + key factory + metrics

Source: `../plan.md` §§11.7, 11.14, 11.39 пп.5–6, R6.2/R6.4.
Depends on: T29 (ProbeCache + CachePolicy), T20/T22 (ProbeExecutor wiring).

## Scope

Worker-side integration: before dispatching a probe to a tool, consult the
shared cache; on hit, reconstruct the raw result without network I/O; on
miss, execute and store. Includes the `ProbeCacheKeyV3` factory (worker has
no Postgres access — all identity inputs come from the task + node config)
and hit/miss metrics. The worker still makes no domain decisions: it caches
raw evidence only, exactly what the tool returned (INV-014/015 unchanged).

## Plan references

- §11.7: cache lookup by `ProbeCacheKeyV3`; hits feed the same evidence
  pipeline as fresh executions
- §11.14: shared cache ON; cross-tenant saving is the point
- §11.39 пп.5–6: `ProbeSingleResult` = untouched `ProbeToolResult`; cached
  results must be indistinguishable downstream (timings/observations
  preserved verbatim)
- §11.20: `CachePoisoningSuspected` event type reserved, not emitted here

## Deviation to expect (report if blocked)

`ProbeCacheKeyV3` has no explicit `probeType` field (V3 folded probe identity
into `probeProfileVersion`/`probeSemanticsVersion`). Do NOT change the V3
schema. Instead the key factory composes
`probeSemanticsVersion = "<probeType->value>@<semantics-base>"` so different
probe types cannot collide under one profile version. Document this as a
justified deviation (additive usage of an existing string field, key stays
deterministic and tenant-free).

## Classes to create

### `src/Audit/ProbeCacheKeyFactory.php`

```php
final readonly class ProbeCacheKeyFactory
{
    /** @param array<string,mixed> $nodeIdentity config: checker_node_id, egress_identity, semantics versions */
    public function __construct(private readonly array $nodeIdentity) {}

    public function build(AuditTaskV1 $task, ProbeType $probeType, string $semanticsBase): ProbeCacheKeyV3;
}
```

- `endpointIdentity` from the task's endpoint snapshot (canonicalized via
  `EndpointIdentity`)
- `credentialFingerprint` from the task's credential fingerprint field
  (already HMAC — the worker never sees the credential itself)
- `judgeSetVersion` / `telegramDcSetVersion` / `probeProfileVersion` from
  task + config dictionary versions (same sources as T25)
- `checkerNodeId` / `egressIdentity` from node identity config (MVP:
  node-1/local per §11.8)

### `src/Audit/ProbeCacheMetrics.php` (interface)

```php
interface ProbeCacheMetrics
{
    public function hit(string $kind): void;
    public function miss(string $kind): void;
    public function negativeHit(string $kind): void;
    public function stored(string $kind): void;
}
```

Implementation `LaravelCacheProbeCacheMetrics`: monotonic counters via
`Cache::increment` on `proxy:probe-cache:metrics:<counter>:<kind>` with a
long TTL; counts are operational runtime data (Redis), never Postgres.

### `src/Checker/CacheAwareProbePlanner.php`

Decorates the probe execution loop (composition over `ProbeExecutor`, not
inheritance):

```php
final class CacheAwareProbePlanner
{
    public function __construct(
        private readonly ProbeExecutor $executor,
        private readonly ProbeCache $cache,
        private readonly CachePolicy $policy,
        private readonly ProbeCacheKeyFactory $keyFactory,
        private readonly ProbeCacheMetrics $metrics,
    ) {}

    /** @return list<ProbeSingleResult> */
    public function run(AuditTaskV1 $task, ProbeExecutionSpecV1 $probe, ?JudgeSetSnapshot $judgeSet): array;
}
```

Semantics:

- `policy->enabled === false` → straight executor delegation, zero cache I/O
- cache HIT → rebuild `ProbeSingleResult` with
  `ProbeToolResult::ok(payload, timings)` (payload/timings from the stored
  entry, verbatim), count `hit`, no network dispatch
- negative HIT → short-circuit with a fresh `ProbeToolResult::failed`
  carrying the same `FailureCode` (via `ToolTimeoutFactory`-style
  construction), count `negativeHit`, no dispatch
- MISS → run the executor; store each successful judge-fanout result as the
  kind-mapped `SharedCacheValue` (observations flattened to scalars; non-
  scalar observation values are dropped before put — `SharedCacheValue`
  rejects them, and dropping must not throw); on tool failure store the
  negative marker; count `miss` + `stored`
- never cache when `ProbeToolResult` carries an `ExecutionFailure` other
  than the classifyable TOOL_* codes (transport timeouts ARE negative-cached;
  ambiguous execution errors are not)

## Tests

### `tests/Feature/Audit/ProbeCacheKeyFactoryTest.php`

- Deterministic: same task+probe → same hash; probeType change → different
  hash; credentialFingerprint taken from task verbatim (no credential material
  in the key — assert no plaintext in `toString()`)

### `tests/Feature/Audit/CacheAwareProbePlannerTest.php`

- Miss → executor called once, result stored, second run with cold executor
  (executor fake asserting zero invocations) → identical result from cache,
  metrics hit=1
- Negative: first run fails with a classifyable TOOL_* code → second run
  short-circuits with the same failure code, no dispatch
- Non-classifiable execution failure → nothing stored, next run dispatches
- Disabled policy → executor always called, zero cache reads
- Cached result is byte-identical downstream: `ProbeExecutionOutcome` counters
  match a fresh run (evidence pipeline unchanged)

## Verify command

```bash
timeout 120 ../../../vendor/bin/pest --filter=ProbeCacheKeyFactory
timeout 120 ../../../vendor/bin/pest --filter=CacheAwareProbePlanner
```
