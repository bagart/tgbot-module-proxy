# T11 — RawFeedEntry model: import staging + idempotency

Plan ref: plan §§11.21, 11.28, 11.35 п.16–17, 11.37 R6.8.
Depends on: T01 (Laravel skeleton + migration harness), T02 (tenancy).

## Goal

Staging table for raw import candidates. Every import source (bot paste,
file upload, feed sync, API, CLI) writes raw lines here before the parser
processes them. Enables idempotent re-runs (batch hash), audit trail, and
feed→tenant projection (plan §11.35 п.16–17).

## Deliverables (file-by-file)

### Migration

- `database/migrations/2026_08_28_000001_create_raw_feed_entries_table.php`
  columns:
  - `id` uuid PK (`HasUuids`);
  - `tenant_id` foreignId constrained to `users`, NOT NULL;
  - `source_id` foreignId nullable constrained to `proxy_sources` (T06);
  - `import_batch_id` uuid NOT NULL — groups entries from the same import run
    (UUID generated per ImportProxiesCommand invocation; enables idempotent
    re-runs by batch hash);
  - `batch_hash` string(64) NOT NULL — SHA-256 of normalized input
    (`trim(lower($sourceText))` per plan §11.19 import-batch dedup key);
  - `raw_line` text NOT NULL — original line as provided (trimmed);
  - `line_number` integer NOT NULL — 1-based position in source text;
  - `status` string NOT NULL default `'pending'` — enum
    `RawFeedEntryStatus::{Pending,Parsed,Skipped,Error}`;
  - `parsed_entry_json` json nullable — serialized `ParsedEntry` (from T10)
    once parsed; null while pending;
  - `parse_error_json` json nullable — serialized `ParseError` if parse failed;
  - `endpoint_id` foreignId nullable constrained to `proxy_endpoints` — set
    after T12 creates the endpoint (linkage for audit);
  - `timestamps`;
  indexes: UNIQUE `(tenant_id, batch_hash, line_number)` — prevents duplicate
  lines within the same batch; INDEX `(tenant_id, status)`;
  INDEX `(tenant_id, import_batch_id)`.

### Model

- `src/Models/RawFeedEntry.php`
  ```php
  final class RawFeedEntry extends Model
  {
      use BelongsToTenant;
      use HasFactory;
      use HasUuids;

      protected $fillable = [
          'source_id', 'import_batch_id', 'batch_hash',
          'raw_line', 'line_number', 'status',
          'parsed_entry_json', 'parse_error_json', 'endpoint_id',
      ];

      protected function casts(): array { ... }
  }
  ```
  - Uses `BelongsToTenant` (T02) for auto-scoping.
  - `$hidden` not needed (no secrets — raw_line is the user's input text).
  - Relationships: `source()` → BelongsTo `ProxySource`; `endpoint()` →
    BelongsTo `ProxyEndpoint`.

### Enum

- `src/Models/RawFeedEntryStatus.php`
  ```php
  enum RawFeedEntryStatus: string
  {
      case Pending = 'pending';
      case Parsed = 'parsed';
      case Skipped = 'skipped';
      case Error = 'error';
  }
  ```

### Factory

- `database/factories/RawFeedEntryFactory.php`
  - Namespace: `BAGArt\ProxyOperations\Database\Factories`
  - Default state: valid pending entry with `raw_line = '1.2.3.4:1080'`,
    `line_number = 1`, `batch_hash` from sha256 of raw_line.
  - States: `pending()`, `parsed()`, `withEndpoint()` (sets `endpoint_id`
    from a `ProxyEndpointFactory`).

## Conventions

- `batch_hash` is computed by the caller (T12) before inserting; the model
  does NOT auto-compute it — this keeps the model thin.
- `import_batch_id` is a UUID generated once per `ImportProxiesCommand`
  invocation; all entries from the same import share it.
- `parsed_entry_json` stores the full `ParsedEntry` except `secret` (which
  is excluded by `ParsedEntry::jsonSerialize()`). This is intentional:
  the raw credential text is preserved in `raw_line` for re-parse if needed,
  but the structured secret is never persisted unencrypted.
- Status transitions: Pending → Parsed | Skipped | Error. The application
  service (T12) drives transitions; the model has no transition logic.

## Tests

`tests/Feature/Models/RawFeedEntryTest.php`:
- factory creates row; `tenant_id` auto-filled from TenantContext;
- queries without TenantContext throw (negative tenancy);
- unique constraint on `(tenant_id, batch_hash, line_number)` — duplicate
  line in same batch rejected (negative idempotency);
- same `(batch_hash, line_number)` allowed in a second tenant (cross-tenant);
- `status` cast to `RawFeedEntryStatus` enum;
- `parsed_entry_json` roundtrip through `ParsedEntry::fromJson()`;
- `source()` and `endpoint()` relationships resolve correctly.

## Verify

```
timeout 120 ../../../vendor/bin/pest --testsuite Feature
timeout 120 ../../../vendor/bin/pest
```

## Out-of-scope

- Parser logic (T10), import orchestration (T12).
- Feed sync system (Stage 9+ — `SystemFeedDefinition`, `FeedSyncRun`).
- Retention of staging entries (WorkspacePolicy, future task).
