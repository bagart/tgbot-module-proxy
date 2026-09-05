# T23 — AuditJob / Attempt / PolicySnapshot persistence

Source: `../plan.md` §§11.18, 11.21, 11.35 пп.4,7; Stage 5 scope.

## Scope

Persistence layer for the audit pipeline: `proxy_audit_jobs`,
`proxy_audit_attempts`, `policy_snapshots` tables + Eloquent models +
factories. Job = logical user intent; Attempt = one execution try;
TaskDelivery identity stays in Redis/T92 keys (no table). The immutable
`AuditPolicySnapshot` DTO (T93) gets a durable row so a job survives Redis
loss (Postgres = domain truth, INV-003).

## Dependencies

| Depends on | Reason |
|---|---|
| T02 | TenantContext / BelongsToTenant (INV-006) |
| T05 | `ProxyAccess` — access_id FK target |
| T08 | `ProxyPolicy` — snapshot source data |
| T93 | `AuditPolicySnapshot` DTO (serialize into snapshot row) |

## Plan references

- §11.18: AuditTrigger enum, job model fields, attempts as separate rows,
  Job → Attempt → TaskDelivery split (§11.37 R6.7), idempotent placement
- §11.21: `proxy_audit_jobs/_attempts`, `policy_snapshots` — tenant NOT NULL,
  written by application layer
- §11.35 п.7: AuditPolicySnapshot contents (probe profile, lifecycle
  thresholds, judge set, targets, governors, retry policy + policy_version);
  retention/UI/export flags do NOT enter the snapshot

## Migration + model details

### `proxy_audit_jobs`

`id (uuid)`, `tenant_id` (FK users), `trigger` (string: manual/scheduled/
import/feed/lazy_selection/recovery/tg_check), `policy_snapshot_id`
(FK policy_snapshots), `requested_by` (nullable user id), `target_set_hash`
(string), `status` (string: pending/queued/running/completed/failed/
cancelled), `created_at`, `started_at`, `completed_at`, `result_code`
(nullable string). Unique index for placement idempotency is handled by
T24 via Redis dedup key — no DB unique constraint (TTL window semantics).

### `proxy_audit_attempts`

`id (uuid)`, `job_id` (FK), `tenant_id`, `attempt_no` (int), `worker_node`
(nullable string), `status` (string: pending/delivered/running/completed/
failed/lost), `result_code` (nullable string), `started_at`, `finished_at`.
Unique constraint `(job_id, attempt_no)` — worker-results idempotency
(§11.19: `task_id + attempt_id`, вечный TTL).

### `policy_snapshots`

`id (uuid)`, `tenant_id`, `policy_version` (int), `snapshot`
(JSON — serialized `AuditPolicySnapshot`), `created_at`. Immutable: no
updates (model guard like T07's `ImmutableRecordException` pattern).

### Classes

- `src/Models/AuditTrigger.php` — string-backed enum (TitleCase keys:
  `Manual='manual'`, `Scheduled`, `Import`, `Feed`, `LazySelection`,
  `Recovery`, `TgCheck`)
- `src/Models/AuditJobStatus.php`, `src/Models/AuditAttemptStatus.php` —
  string-backed enums
- `src/Models/ProxyAuditJob.php` — BelongsToTenant, relations
  `policySnapshot()`, `attempts()`; scope helpers by status/trigger
- `src/Models/ProxyAuditAttempt.php` — BelongsToTenant, `job()` relation
- `src/Models/PolicySnapshot.php` — BelongsToTenant, immutable-after-create
  guard, `toDto(): AuditPolicySnapshot` / `static fromDto(...)` bridge
- Factories for all three models

## Tests

### `tests/Feature/Audit/AuditJobModelTest.php`

- Job create → persists trigger/status/snapshot link; tenant scoping
- Negative tenant scoping: job of another tenant invisible (INV-006)
- Attempt unique `(job_id, attempt_no)` violation throws
- PolicySnapshot row is immutable (update throws)
- PolicySnapshot toDto/fromDto round-trip preserves AuditPolicySnapshot
- AuditTrigger/AuditJobStatus enum values match plan naming

## Verify command

```bash
timeout 120 ../../../vendor/bin/pest --filter=AuditJobModel
```
