# T21 — ExecutionResultNormalizer

Source: `../plan.md` §§11.9, 11.16, 11.39 пп.13–14, 11.14 Stage 4.

## Scope

Transforms raw `ProbeExecutionOutcome` (list of `ProbeSingleResult`) into the
wire-level `AuditResultV1`. This is the boundary where raw probe observations
are classified into `ProxyFailure` (proxy-side: TCP_TIMEOUT, AUTH_FAILURE, etc.)
versus `ExecutionFailure` (checker-side: TOOL_TIMEOUT, TOOL_CRASH, etc.) — the
structural invariant INV-014/015.

## Dependencies

| Depends on | Reason |
|---|---|
| T20 | `ProbeExecutionOutcome`, `ProbeSingleResult` — input DTOs |
| T90 | `AuditResultV1`, `AuditResultStatus` — output DTOs |
| T88 | `FailureCode`, `FailureClass`, `FailureDescriptor`, `FailureTaxonomy` |

## Plan references

- §11.9: AuditResultV1 carries observations (proxy failures + raw context) and
  executionFailures (checker/platform faults) — strictly separated
- §11.16: Failure taxonomy — TOOL_* codes are CHECKER class, never proxy
  observations; `ExecutionFailure ≠ ProxyFailure`
- §11.39 пп.13–14: ProbeFailure vs InfrastructureFailure — tool fault does not
  become a proxy observation (INV-014/015)
- §11.35 п.9: Evidence pipeline starts with raw probe outcomes; normalizer is
  the first stage

## Classes to create

### `src/Checker/ExecutionResultNormalizer.php`

```php
final readonly class ExecutionResultNormalizer
{
    public function __construct(
        private readonly FailureTaxonomy $taxonomy,
    ) {}

    public function normalize(
        ProbeExecutionOutcome $outcome,
        string $checkerNodeId,
    ): AuditResultV1
}
```

Responsibilities:
1. Iterate `$outcome->results`
2. For each `ProbeSingleResult`:
   a. If `$toolResult->ok === true`:
      - Create `ProxyFailure` with `FailureDescriptor` for the specific success
        pattern (note: success observations carry context data in `ProxyFailure`
        context — this is the raw observation payload for downstream evidence
        pipeline)
      - Actually, on success: NO failure is created. The raw observations are
        passed through as `context` on a synthetic success entry. Review
        existing `AuditResultV1` — it expects `list<ProxyFailure>` for
        observations. On success, observations is empty; raw data travels in
        timings.
      - **Design decision**: `AuditResultV1.observations` carries proxy-side
        failures only. Successful probe data (latency, headers, exit_ip) travels
        in `$toolResult->observations` which the normalizer packs into the
        `$timings` field or a dedicated `probeResults` extension. For V1,
        successful observations are serialized as context on zero-length
        observations list, and the downstream health engine reads them from the
        AuditResult envelope.
   b. If `$toolResult->ok === false`:
      - Check `$toolResult->failure->descriptor->class`:
        - `Checker` or `Platform` → add to `executionFailures` list
        - `Proxy`, `Target`, `Judge`, `Policy` → add to `observations` list
3. Determine `AuditResultStatus`:
   - All probes ok → `Completed`
   - Any execution failure but no proxy failure → `Failed`
   - Mix → `Completed` (proxy failures are expected outcomes, not task failures)
   - All execution failures → `Failed`
4. Compute aggregate timings from per-probe `timingsMs`
5. Return `AuditResultV1`

### `src/Checker/ProbeOutcomeClassifier.php`

```php
final readonly class ProbeOutcomeClassifier
{
    public function classify(ProbeToolResult $result): ProbeOutcomeClassification
}
```

Pure classifier: given a `ProbeToolResult`, returns whether it represents a
proxy failure, execution failure, or success. Used by the normalizer.

### `src/Checker/ProbeOutcomeClassification.php`

```php
enum ProbeOutcomeClassification: string
{
    case Success = 'success';
    case ProxyFailure = 'proxy_failure';
    case ExecutionFailure = 'execution_failure';
}
```

## Tests

### `tests/Unit/Checker/ExecutionResultNormalizerTest.php`

- All probes successful → status=Completed, observations=[], executionFailures=[]
- Proxy failure (TCP_TIMEOUT) → observations contains ProxyFailure, status=Completed
- Execution failure (TOOL_TIMEOUT) → executionFailures contains ExecutionFailure, status=Failed
- Mixed proxy + execution failures → status=Completed, both lists populated
- Empty outcome → status=Completed with empty lists
- CheckerNodeId propagated to result
- Aggregate timings sum correctly
- INV-014: TOOL_* code never appears in observations
- INV-015: Proxy-side code never appears in executionFailures
- Failure code → FailureDescriptor resolution via FailureTaxonomy

### `tests/Unit/Checker/ProbeOutcomeClassifierTest.php`

- ok=true → Success
- ok=false, failure class Checker → ExecutionFailure
- ok=false, failure class Proxy → ProxyFailure
- ok=false, failure class Platform → ExecutionFailure
- ok=false, failure class Policy → ProxyFailure

## Verify command

```bash
timeout 120 ../../../vendor/bin/pest --filter=ExecutionResultNormalizer
timeout 120 ../../../vendor/bin/pest --filter=ProbeOutcomeClassifier
```
