# T08 — ProxyPolicy: WorkspacePolicy, quotas & governors data (migration + model + factory)

Plan ref: §7 rows #8 (quotas part), #43-adjacent; plan §§11.18 п.7
(snapshot split), 11.21 (`proxy_policies`), 11.29 (`WorkspaceSettings`/`QuotaPolicy`).
Depends on: T02.

## Goal

Per-workspace policy record: quotas instead of roles (§4), retention flags
(default disabled — "no deletion"), export rules (credentials export opt-in),
UI flags (`web_panel_enabled` default false), politeness budget placeholders.
This is `WorkspacePolicy` — deliberately NOT what gets snapshot into jobs
(`AuditPolicySnapshot` stays a Stage-0 DTO, §11.35 п.7).

## Deliverables (file-by-file)

- `database/migrations/2026_08_27_000008_create_proxy_policies_table.php`
  columns:
  - `id` uuid PK;
  - `tenant_id` foreignId constrained NOT NULL UNIQUE (one policy row per workspace);
  - `version` string NOT NULL default `v1` (bumped on every material change,
    consumed by future AuditPolicySnapshot builder);
  - `quotas` json NOT NULL — `{max_endpoints, jobs_per_day, concurrent_jobs,
    max_import_file_bytes}` with defaults from `config('proxy-operations.quotas')`
    (§4: quotas replace roles);
  - `politeness` json NOT NULL default `[]` (#21 budgets placeholder:
    per-target rate limits, egress class);
  - `retention` json NOT NULL — `{enabled: false}` default ("no deletion" §10.11);
  - `export_rules` json NOT NULL — `{with_credentials_opt_in: false,
    rate_limit_per_day}` (§11.23 export action gates);
  - `ui_flags` json NOT NULL — `{web_panel_enabled: false}` (§10.12 п.12);
  - `timestamps`;
- `src/Models/ProxyPolicy.php` — `HasFactory`, `HasUuids`, `BelongsToTenant`;
  typed accessors returning readonly config-DTO style values (e.g.
  `quotas(): QuotaPolicy`) where consumers exist; otherwise raw arrays.
  Lazy row creation helper: `static::forCurrentTenant(): self` creating the
  defaults row on first access (workspace created lazily on first action, §4).
- `database/factories/ProxyPolicyFactory.php`.
- Optional small DTO `src/Models/Dto/QuotaPolicy.php` — `final readonly`,
  constructor-promoted, only if the model accessor needs it (no dead code).

## Conventions

- Defaults live in `config/proxy-operations.php`, not hardcoded in model/migration.
- No snapshot logic here; job snapshots are built later from this + system dicts.

## Tests

`tests/Feature/Models/ProxyPolicyTest.php`:
- one row per tenant enforced (negative duplicate case);
- `forCurrentTenant()` creates once and returns same row on second call;
- defaults match config values; changing config does not mutate existing rows;
- cross-tenant invisibility + no-context throw (negative tenancy cases).

## Verify

```
timeout 120 ../../../vendor/bin/pest --testsuite Feature
timeout 120 ../../../vendor/bin/pest
```

## Out-of-scope

- Policy engine/YAML/presets/rollback (#43, Stage 10+), governors enforcement
  runtime (#8 execution part, checker stages), settings UI/bot forms.
