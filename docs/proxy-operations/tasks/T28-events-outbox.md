# T28 — Events, outbox dispatcher, end-to-end pipeline tests

Source: `../plan.md` §§11.9, 11.20, 11.21, 11.35 пп.18–19, §11.37 R6.7; Stage 5 exit.

## Scope

`proxy_events` table (immutable event log + transactional outbox in one
table), `AuditEventRecorder` implementation (appends `EventEnvelope` rows
inside the ingestion transaction), and the post-commit dispatcher
(at-least-once, consumer idempotency by `event_id`). Closes the stage with
end-to-end feature tests including failure injection.

## Dependencies

| Depends on | Reason |
|---|---|
| T26 | ingestion unit-of-work + `AuditEventRecorder` contract |
| T27 | real `HealthEvaluator` (lifecycle events) |
| T92 | `EventEnvelope`, `SequenceNumber` |
| T02 | tenancy |

## Plan references

- §11.20: envelope `{event_id, event_type, schema_version, occurred_at,
  tenant_id, aggregate_id, payload}`; three event classes (Domain /
  Integration / Operational); outbox → dispatcher at-least-once
- §11.35 п.18: strict ordering — events appended in the same transaction as
  observation/health/lifecycle; projections/notifications only AFTER commit
- §11.35 п.19 + R6.7: one table = event log + outbox; payload/identity/
  occurred_at immutable, dispatch metadata (`dispatch_status`, `attempt_count`,
  `last_attempt_at`, `consumed_at`) mutable by the dispatcher; dispatched
  events are never deleted (archival via partitions)

## Migration + classes

### `proxy_events`

`id` (ULID string PK = event_id), `tenant_id`, `event_type` (string),
`schema_version` (int), `occurred_at`, `aggregate_id` (string),
`aggregate_type` (string), `payload` (JSON), `sequence`
(bigint per-tenant monotonic — T92 `SequenceNumber`), plus dispatcher
columns: `dispatch_status` (pending/dispatched/failed),
`attempt_count` (int, default 0), `last_attempt_at` (nullable),
`consumed_at` (nullable). Indexes: `(tenant_id, sequence)`,
`(dispatch_status)`.

### `src/Audit/DbAuditEventRecorder.php`

Implements `AuditEventRecorder`: builds `EventEnvelope` (ULID event_id,
schema_version from a per-type registry map), inserts the row. Called
inside the ingestion transaction (T26).

### `src/Audit/EventTypeRegistry.php`

Maps event type → schema_version + class (Domain:
`access.state_changed`, `quarantine.changed`; Integration:
`audit.completed`; Operational: `worker.failed`, `judge.unavailable`).
Readonly config-DTO built from `config/proxy-operations.php` → `audit.events`.

### `src/Audit/EventOutboxDispatcher.php`

Batch dispatcher: select pending rows `FOR UPDATE SKIP LOCKED`, mark
in-flight with attempt increment, hand envelopes to registered consumers
(`AuditEventConsumer` interface — `handle(EventEnvelope): void`), mark
dispatched on success / failed with attempts on throw. Post-commit only;
at-least-once (consumers must dedup by event_id). ASK tickable adapter
`EventOutboxTick` for periodic invocation (pattern of T18 daemon tickables).

## Tests

### `tests/Feature/Audit/EventOutboxTest.php`

- Recorder inserts envelope rows with correct envelope fields + per-tenant
  sequence ordering
- Event row immutable payload: update of payload/occurred_at throws;
  dispatcher columns updatable
- Dispatcher: pending → consumed by fake consumer → dispatched +
  consumed_at; consumer throw → status failed, attempts incremented,
  retried on next run; two concurrent dispatchers don't double-handle
  (SKIP LOCKED); consumer sees each envelope at least once (fake may
  re-deliver — dedup by event_id asserted on the consumer side)

### `tests/Feature/Audit/AuditPipelineEndToEndTest.php` (stage exit)

Full flow with failure injection:
1. JobStarter (T24) → DeliveryDispatcher (T25, fake queue) → build
   `AuditResultV1` (success + injected failure variants) →
   ResultIngestionService (T26) with real DimensionalHealthEvaluator (T27)
   + real recorder
2. Assertions: observation row, health row, access state transition,
   job/attempt terminal statuses, events in outbox in one transaction
3. Failure injections: worker crash result (execution failures only) →
   job failed + `worker.failed` operational event; duplicate result
   delivery → exactly one observation; dispatcher consumer throwing →
   event retried, no data loss
4. Post-commit projection hook runs only after COMMIT (fake projector
   asserting ordering)

## Verify command

```bash
timeout 120 ../../../vendor/bin/pest --filter=EventOutbox
timeout 120 ../../../vendor/bin/pest --filter=AuditPipelineEndToEnd
```
