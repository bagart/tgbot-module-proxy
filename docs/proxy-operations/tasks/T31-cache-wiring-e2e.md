# T31 — Shared cache ON: wiring + end-to-end tests

Source: `../plan.md` §§11.14, 11.35 пп.5–6; stage-06 exit criteria.
Depends on: T29, T30.

## Scope

Turn the shared cache on across the checker pipeline (§11.14: stage 6 is
where shared cache goes live): container wiring in
`ProxyOperationsServiceProvider`, integration into the checker execution
path from T22, and end-to-end feature tests including cross-tenant sharing
and failure injection.

## Plan references

- §11.14: shared probe-cache ON at stage 6; interpretation stays per-tenant
- §11.7: cached raw observation feeds the normal evidence pipeline —
  `ExecutionResultNormalizer` sees identical input shape
- §11.9: cache is Redis runtime only; Postgres untouched by cache logic

## Changes

### `src/ProxyOperationsServiceProvider.php`

Extend the existing checker/audit registration:

- singleton `CachePolicy` built from `config('proxy-operations.audit.cache')`
- bind `ProbeCache` → `LaravelCacheProbeCache` (singleton; lazy store access)
- bind `ProbeCacheMetrics` → `LaravelCacheProbeCacheMetrics`
- singleton `ProbeCacheKeyFactory` from config node identity
  (`audit.cache.checker_node_id` / `egress_identity`, MVP values `node-1` /
  `local`, plus `probe_semantics_version` base string)
- wherever the checker executor is resolved for probe runs (T22 wiring),
  wrap with `CacheAwareProbePlanner` when the policy is enabled; keep the
  plain `ProbeExecutor` reachable for tests that need cache-off behavior

### `config/proxy-operations.php`

Add `audit.cache.checker_node_id`, `audit.cache.egress_identity`,
`audit.cache.probe_semantics_version` (string, e.g. `'v1'`) to the section
from T29.

## Tests

### `tests/Feature/Audit/SharedCacheEndToEndTest.php`

The stage-06 exit test. Two tenants, same endpoint + same credential:

1. Import one endpoint+credential for tenant A and the identical pair for
   tenant B (fingerprint must match — R6.2: tenant_id not in fingerprint).
2. Run an audit job for tenant A through the delivery→ingestion path
   (T25/T26 fixtures): cache MISS, executor dispatched, observation stored.
3. Run the same job for tenant B: cache HIT — the executor fake records ZERO
   dispatches, tenant B still gets its own observation row and its own
   per-tenant interpretation (health/lifecycle) — INV-005 respected end to
   end.
4. Negative caching: inject a tool failure for the shared key, rerun →
   short-circuit negative result for both tenants until the negative TTL
   passes (time travel or TTL manipulation via the array store).
5. Failure injection: cache store throwing mid-run → audit still completes
   (cache never breaks the pipeline; result comes from a fresh dispatch).
6. Metrics counters observed: hits/misses/negative hits/stored all move as
   expected.

### Existing suite

- `composer test` full module suite must stay green; if the T22 checker
  wiring test asserts executor resolution shape, update it for the planner
  wrapper (behavior-preserving change).

## Verify command

```bash
timeout 180 ../../../vendor/bin/pest --filter=SharedCacheEndToEnd
timeout 600 ../../../vendor/bin/pest
```
