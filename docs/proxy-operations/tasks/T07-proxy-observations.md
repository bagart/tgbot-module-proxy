# T07 — ProxyObservation: append-only observations (migration + model + factory)

Plan ref: §7 rows #24/IMPROVE#6; plan §§11.7, 11.16, 11.21
(`proxy_observations`), §11.37 R6.4, INV-014/015. Depends on: T05.

## Goal

Append-only, immutable raw observations written from `Wire\AuditResultV1` in
later stages. TOOL_* execution failures never become proxy observations
(INV-014/015); values are tenant-neutral raw evidence only (R6.4 allowlist).

## Deliverables (file-by-file)

- `database/migrations/2026_08_27_000007_create_proxy_observations_table.php`
  columns:
  - `id` uuid PK;
  - `tenant_id` foreignId constrained NOT NULL;
  - `access_id` foreignId → proxy_accesses NOT NULL;
  - `checked_at` timestamp NOT NULL (event time — not now());
  - `probe_type` string NOT NULL (`Domain\Probe\ProbeType`);
  - `probe_profile` string NOT NULL + `probe_profile_version` string NOT NULL;
  - `judge_set_version` string nullable; `checker_node_id` string nullable;
    `checker_region` string nullable (metadata per R6.2);
  - `outcome` string NOT NULL (`success|failure`);
  - `failure_code` string nullable (`Domain\Failure\FailureCode`) +
    `failure_class` string nullable (`Domain\Failure\FailureClass`);
    CHECK-style guard: outcome=success ⇒ failure_code IS NULL (and vice versa);
  - `evidence` json NOT NULL — safe raw payload only: timings, status,
    content_length, body_hash, bytes_received, exit_ip, dns observations,
    allowlisted headers (R6.4 denylist enforced by writer contract later);
  - `schema_version` int NOT NULL default 1 (IMPROVE#6);
  - `created_at` only (no `updated_at`);
  indexes: `(tenant_id, access_id, checked_at DESC)` and
  `(access_id, checked_at DESC)` for shared read paths (§11.21).
  Partitioning note: plain table now; Postgres declarative partitioning is a
  dedicated infra migration once deployment DB lands (see README open decision OD-2).
- `src/Models/ProxyObservation.php` — `HasFactory`, `HasUuids`,
  `BelongsToTenant`; immutability guard: model raises on `->update()` /
  attribute change after persist / delete (override `performUpdate` to throw
  `ImmutableRecordException`); casts to ProbeType/FailureCode/FailureClass enums.
- `src/Models/ImmutableRecordException.php` — final `\LogicException`.
- `database/factories/ProxyObservationFactory.php` — states `successful()`,
  `failed(FailureCode $code)`, checkedAt(Carbon $at).

## Conventions

- Append-only means no UPDATE and no DELETE path in module code.
- Evidence JSON must not contain credentials/auth headers/request bodies (R6.4).

## Tests

`tests/Feature/Models/ProxyObservationTest.php`:
- factory roundtrip with enum casts;
- update after save throws `ImmutableRecordException`; delete throws too
  (negative append-only cases);
- failure_code present without outcome=failure rejected (negative case);
- index existence asserted via `Schema::getIndexListing` where SQLite supports it;
- cross-tenant invisibility + no-context throw (negative tenancy cases).

## Verify

```
timeout 120 ../../../vendor/bin/pest --testsuite Feature
timeout 120 ../../../vendor/bin/pest
```

## Out-of-scope

- Writer pipeline from AuditResult (Stage 5), aggregation/MetricBuckets (#24
  aggregation part), retention job (disabled by default forever-ish).
