# T26 — AuditResult ingestion + probe-data transport (OD-5)

Source: `../plan.md` §§11.7, 11.9, 11.19, 11.35 пп.1,9,18; Stage 5 scope.

## Scope

Application-layer ingestion of `AuditResultV1`: idempotency check
(`task_id + attempt_id`), attempt/job status update, evidence extraction
from successful probe data, and the transactional unit of work
(Observation insert → Health update → Lifecycle transition → events) whose
 collaborators are wired in T27/T28. **Resolves OD-5**: successful probe
data transport is an additive `probeData` field on `AuditResultV1`.

## OD-5 resolution (decision)

Option (a) from the README: `AuditResultV1` gains an additive readonly
field `probeData` — `array<string, list<array<string,mixed>>>` keyed by
probe type, each entry a raw-observation record. Rules:

- Contents follow the shared-cache value allowlist (§11.35 п.2 / T92
  `SharedCacheValueKind`): exit_ip, latency samples, headers, dns
  observations, timestamps, judge/checker ids. NEVER tenant_id, scores,
  lifecycle state, quarantine, policy-derived anonymity tiers.
- Additive-only change: `SCHEMA_VERSION` stays `1`; `fromJsonV1` treats the
  field as optional (absent → `[]`). ContractCompatibility (T93) marks it
  as a backward-compatible addition.
- Proxy failures remain in `observations` (list<ProxyFailure>) — unchanged.

## Dependencies

| Depends on | Reason |
|---|---|
| T25 | result consumption from the delivery queue |
| T90 | `AuditResultV1` (extend with `probeData`) |
| T07 | `ProxyObservation` model (append-only insert) |
| T89 | Evidence DTOs (`DimensionEvidence`, per-type evidence, `EvidenceApplicability`) |
| T23 | Attempt status updates + unique idempotency |

## Plan references

- §11.7: Observation → Interpretation pipeline entry point
- §11.9: worker never writes Postgres; the application layer runs
  `AuditResult → transaction → Observation → Health → Lifecycle → Events`
- §11.35 п.1: evidence/health belongs to ProxyAccess (access_id)
- §11.35 п.18: single DB transaction for observation+health+lifecycle+events;
  projections only after commit

## Changes

### `src/Wire/AuditResultV1.php` (modify)

Add `public readonly array $probeData = []` as the last constructor param
(optional, default `[]`); include in `jsonSerialize()`; parse in
`fromJsonV1()` when present. Additive only — no renames/removals.

### `src/Audit/ResultIngestionService.php`

```php
final class ResultIngestionService
{
    public function __construct(
        private readonly ProbeDataEvidenceExtractor $evidenceExtractor,
        private readonly HealthEvaluator $healthEvaluator,      // interface, impl T27
        private readonly AuditEventRecorder $eventRecorder,     // interface, impl T28
        private readonly ObservationWriter $observationWriter,
    ) {}

    public function ingest(AuditResultV1 $result): IngestionOutcome;
}
```

Behavior:
1. Idempotency: attempt already `completed` with the same `task_id +
   attempt_id` → return `IngestionOutcome::duplicate()` (no writes)
2. Load attempt + job + access by ids; tenant mismatch → reject (tenant_id
   in the result is metadata; authorization comes from the DB rows)
3. Build `list<DimensionEvidence>` via `ProbeDataEvidenceExtractor`
4. One DB transaction: append `ProxyObservation` (safe-evidence JSON,
   schema_version — T07), update attempt/job status + `result_code`, call
   `HealthEvaluator->evaluate(access, evidence)` (persists health +
   lifecycle decision), `AuditEventRecorder->record(...)` appends events
   inside the same transaction (outbox dispatch is T28's dispatcher, after
   commit)
5. Return `IngestionOutcome` (readonly DTO: duplicate flag, observation id,
   state transition or null)

### `src/Audit/ProbeDataEvidenceExtractor.php`

Maps `probeData` records per probe type to T89 evidence DTOs
(`TcpEvidence`, `TlsEvidence`, `HttpEvidence`, `UdpEvidence`,
`DnsEvidence`, `TelegramEvidence`, `BandwidthEvidence`, `JudgeEvidence`)
with `EvidenceApplicability` per access protocol (MTProto → HTTP/UDP
`NOT_APPLICABLE`, §11.35 п.9). Unknown probe types are ignored (forward
compatibility).

### `src/Audit/ObservationWriter.php`

Append-only insert guard reuse from T07 (immutability); stores
`probe_profile_version` and `policy_snapshot_id` alongside (R6.6
explainability).

### `src/Audit/AuditEventRecorder.php` (interface, defined here)

```php
interface AuditEventRecorder
{
    /** Append domain/integration events inside the ingestion transaction. */
    public function record(EventEnvelope $envelope): void;
}
```

Implementation is T28's `DbAuditEventRecorder`.

### `src/Audit/IngestionOutcome.php` — readonly DTO.

## Tests

### `tests/Feature/Audit/ResultIngestionServiceTest.php`

- Happy path: result with probeData + no failures → observation row,
  attempt completed, job completed, evidence passed to evaluator
  (fake evaluator captures input)
- Duplicate delivery (same task_id+attempt_id) → no second observation
- Proxy failures only → observation with failure evidence, health evaluator
  still invoked (failure evidence counts, §11.7)
- Foreign tenant result → rejected, nothing written
- Transactional ordering: evaluator/event-recorder fakes record inside the
  DB transaction (assert via `DB::transactionLevel()` / after-commit hook)
- `AuditResultV1` JSON round-trip with and without probeData; old payloads
  (no probeData) still parse (backward compat)
- Extractor: latency samples → BandwidthEvidence/HttpEvidence records;
  MTProto access → HTTP/UDP evidence NOT_APPLICABLE; unknown probe type ignored

## Verify command

```bash
timeout 120 ../../../vendor/bin/pest --filter=ResultIngestion
timeout 120 ../../../vendor/bin/pest --filter=ProbeDataEvidenceExtractor
```
