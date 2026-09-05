# T20 — ProbeExecutor

Source: `../plan.md` §§11.30, 11.39 пп.5–6,8, 11.14 Stage 4.

## Scope

Central orchestrator for probe execution: receives an `AuditTaskV1`,
plans the concrete probe executions (resolving judges, building
`ProbeExecutionContext` per probe), dispatches to registered `ProbeTool`
implementations, and collects raw `ProbeToolResult` outcomes. This is the
"orchestration only" worker brain — no domain decisions, no Postgres writes.

## Dependencies

| Depends on | Reason |
|---|---|
| T18 | `ResourceGovernor`, `TransportAdapterResolver`, worker wiring |
| T19 | `JudgeProvider`, `JudgeSetProvider` |
| T90 | `AuditTaskV1`, `ProbeExecutionSpecV1`, `AuditResultV1` wire contracts |
| T91 | `ProbeTool`, `ToolRegistry`, `ToolManifest`, `ProbeExecutionContext`, `ProbeToolResult` |

## Plan references

- §11.30: Worker = orchestration only; Redis-only runtime (INV-003/009)
- §11.39 пп.5–6: Worker receives AuditTaskV1, builds ProbeInput per probe,
  executes via ProbeTool contract, collects results
- §11.39 п.8: ProbeTool contract — domain sees this, never proc_open
- §11.39 п.15: Resource Governor enforcement before each probe
- §11.39 п.19: PHP owns domain decisions; executor is orchestration, not domain

## Classes to create

### `src/Checker/ProbeExecutor.php`

```php
final class ProbeExecutor
{
    public function __construct(
        private readonly JudgeProvider $judgeProvider,
        private readonly ToolRegistry $toolRegistry,
        private readonly ResourceGovernor $governor,
        private readonly ToolTimeoutFactory $timeoutFactory,
    ) {}

    public function execute(AuditTaskV1 $task): ProbeExecutionOutcome
}
```

Responsibilities:
1. Iterate `$task->probes` (list of `ProbeExecutionSpecV1`)
2. For each probe:
   a. Check governor → if at capacity, record `TOOL_UNAVAILABLE` execution
      failure and skip
   b. Resolve tool via `ToolRegistry` by matching `ProbeType` against
      `ToolCapabilities::probeTypes`
   c. For judge-dependent probes (http_liveness, header_marker,
      anonymity_headers): call `JudgeProvider::select()` to get judge URLs;
      each judge = separate probe execution
   d. Build `ProbeExecutionContext` from endpoint snapshot + credential
      (sealed or reference) + probe spec + governor limits
   e. Execute `$tool->execute($context)` inside a try/catch:
      - Success → collect `ProbeToolResult::ok()` observations
      - `Throwable` → wrap as `ExecutionFailure(ToolCrash)` with stack trace
        in context
   f. Enforce timeout: if wall-clock exceeds `$spec->timeoutMs`, record
      `ExecutionFailure(ToolTimeout)`
3. Aggregate all outcomes into `ProbeExecutionOutcome`

### `src/Checker/ProbeExecutionOutcome.php`

```php
final readonly class ProbeExecutionOutcome
{
    public function __construct(
        public readonly string $taskId,
        public readonly string $attemptId,
        /** @var list<ProbeSingleResult> */
        public readonly array $results,
        public readonly array $timingsMs,
        public readonly int $probeCount,
        public readonly int $successCount,
        public readonly int $failureCount,
        public readonly int $executionFailureCount,
    ) {}
}
```

### `src/Checker/ProbeSingleResult.php`

```php
final readonly class ProbeSingleResult
{
    public function __construct(
        public readonly ProbeType $probeType,
        public readonly ?string $judgeId,
        public readonly ProbeToolResult $toolResult,
    ) {}
}
```

### `src/Checker/ToolTimeoutFactory.php`

```php
final readonly class ToolTimeoutFactory
{
    /** Convert TimeoutTier enum to milliseconds. */
    public function timeoutFor(TimeoutTier $tier): int
}
```

Maps:
- `Aggressive` → 5 000 ms
- `Standard` → 15 000 ms
- `Generous` → 30 000 ms

## Key invariants enforced

- INV-011: No domain entities (ProxyAccess, Tenant, AuditJob) reach the tool.
  Only `ProbeExecutionContext` with host/port/credentials/probe-spec.
- INV-013: Credentials delivered via `CredentialChannel`, never argv.
- INV-014/015: Tool failures → `ExecutionFailure` only, never proxy observations.
- INV-019: Governor checked before every probe dispatch.
- INV-020: Control plane and execution plane separated (this is execution).

## Tests

### `tests/Unit/Checker/ProbeExecutorTest.php`

- Happy path: AuditTaskV1 with 2 probes → 2 ProbeSingleResult entries
- Judge-dependent probe selects judges via JudgeProvider
- Governor at capacity → TOOL_UNAVAILABLE execution failure recorded, probe skipped
- Tool throws exception → ExecutionFailure(ToolCrash) recorded
- Timeout exceeded → ExecutionFailure(ToolTimeout) recorded
- Mixed success + failure: outcome counts are correct
- Empty probe list → empty outcome with zero counts
- Tool not found in registry → ExecutionFailure(ToolUnavailable) recorded
- Credential delivery: sealed payload passed through to context

### `tests/Unit/Checker/ToolTimeoutFactoryTest.php`

- Aggressive → 5000
- Standard → 15000
- Generous → 30000

## Verify command

```bash
timeout 120 ../../../vendor/bin/pest --filter=ProbeExecutor
timeout 120 ../../../vendor/bin/pest --filter=ToolTimeoutFactory
```
