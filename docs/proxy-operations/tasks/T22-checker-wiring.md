# T22 — Checker Wiring & Integration

Source: `../plan.md` §§11.14 Stage 4, 11.30, 11.39 п.19.

## Scope

Service provider bindings for all Stage 4 checker components, config
extensions for checker-specific settings, and a verification integration test
that proves the full pipeline: `AuditTaskV1 → ProbeExecutor →
ExecutionResultNormalizer → AuditResultV1`.

## Dependencies

| Depends on | Reason |
|---|---|
| T19 | `JudgeProvider` interface + `JudgeSetProvider` |
| T20 | `ProbeExecutor`, `ProbeExecutionOutcome` |
| T21 | `ExecutionResultNormalizer` |
| T01 | Service provider registration pattern |

## Plan references

- §11.14: Stage 4 exit criteria — executor + tools + normalizer + tests green
- §11.30: Worker node structure — control plane, execution plane, tool runtime,
  security boundary
- §11.39 п.19: PHP owns domain decisions; checker components are orchestration

## Changes

### `config/proxy-operations.php` — add checker section

```php
'checker' => [
    // Default probe timeout per tier (ms). Override per-job via AuditPolicySnapshot.
    'timeouts' => [
        'aggressive' => 5000,
        'standard' => 15000,
        'generous' => 30000,
    ],
    // Judge selection: 'round_robin' | 'all' | 'random'.
    'judge_selection' => 'round_robin',
    // Maximum probes per AuditTaskV1 execution (safety valve).
    'max_probes_per_task' => 50,
],
```

### Service provider bindings (in `ProxyOperationsServiceProvider` or deferred provider)

Register as singletons:
- `JudgeProvider` → `JudgeSetProvider`
- `ProbeExecutor`
- `ExecutionResultNormalizer`
- `ProbeOutcomeClassifier`
- `ToolTimeoutFactory`
- `JudgeBudgetTracker` (transient — new instance per execution)

## Tests

### `tests/Feature/Checker/CheckerPipelineIntegrationTest.php`

Full-pipeline integration test (mocked ProbeTool, real DI resolution):

1. Create an `AuditTaskV1` with 2 probe specs (http_liveness + latency_series)
2. Register a mock `ProbeTool` in `ToolRegistry` that returns deterministic
   observations
3. Build `JudgeSetSnapshot` with 2 judges
4. Execute through `ProbeExecutor` → `ProbeExecutionOutcome`
5. Normalize through `ExecutionResultNormalizer` → `AuditResultV1`
6. Assert:
   - `AuditResultV1.status === Completed`
   - `observations` list is empty (all probes succeeded)
   - `executionFailures` list is empty
   - `timings` contains expected keys
   - `checkerNodeId` propagated correctly
   - Schema version is 1

### `tests/Feature/Checker/CheckerFailurePipelineTest.php`

Integration test with mixed outcomes:

1. Register 2 mock tools: one returns success, one throws
2. Execute through full pipeline
3. Assert:
   - `status === Completed` (partial success is still completed)
   - `observations` contains the proxy-side failure
   - `executionFailures` contains the checker-side failure
   - Counts match

## Verify command

```bash
timeout 120 ../../../vendor/bin/pest --filter=CheckerPipeline
timeout 120 ../../../vendor/bin/pest --filter=CheckerFailurePipeline
```
