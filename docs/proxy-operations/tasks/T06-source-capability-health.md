# T06 — ProxySource + ProxyCapability + ProxyHealth (migrations + models + factories)

Plan ref: §7 row #3 (inventory foundation remainder); plan §§11.4–11.5,
11.21 (`proxy_sources`, `proxy_capabilities`, `proxy_health`), §11.37 R6.6.
Depends on: T03, T05.

## Goal

Complete the inventory foundation: sources (where proxies came from), derived
capabilities (endpoint-level + access-level parts) and derived health
(access-scoped). Derived tables are written only by their future engines —
models here are persistence-only.

## Deliverables (file-by-file)

- `database/migrations/2026_08_27_000004_create_proxy_sources_table.php`:
  - `id` uuid PK; `tenant_id` foreignId constrained NOT NULL;
  - `kind` string NOT NULL (`paste|file|feed|manual`) as string enum
    `src/Domain/... or src/Models/SourceKind.php` (new enum, TitleCase);
  - `label` string nullable; `feed_id` string nullable;
  - `import_policy_version` string nullable;
  - `enabled` bool default true; `last_synced_at` timestamp nullable;
  - `timestamps`; INDEX `(tenant_id, enabled)`.
- `database/migrations/2026_08_27_000005_create_proxy_capabilities_table.php`
  (derived):
  - `id` uuid PK; `tenant_id` foreignId constrained NOT NULL;
  - `endpoint_id` foreignId → proxy_endpoints NOT NULL (endpoint-level part);
  - `access_id` foreignId nullable → proxy_accesses (access-level part:
    auth/udp/dns/tg, §11.21);
  - `udp_associate_supported` bool nullable;
  - `dns_resolution_mode` string nullable (`LOCAL_DNS|REMOTE_DNS|PROXY_DNS`, §11.5);
  - `matrix` json NOT NULL default `[]` — protocol capability matrix slice from
    `Domain\Identity\ProtocolCapabilityMatrix` / `ApplicationCapability`;
  - `capability_formula_version` string NOT NULL (R6.6: version next to every
    derived value);
  - `evaluated_at` timestamp nullable; `timestamps`;
  INDEX `(tenant_id, endpoint_id)`; UNIQUE `(tenant_id, endpoint_id, access_id)`
  with NULL-access handling per SQLite semantics.
- `database/migrations/2026_08_27_000006_create_proxy_health_table.php`
  (derived, access-scoped):
  - `id` uuid PK; `tenant_id` foreignId constrained NOT NULL;
  - `access_id` foreignId → proxy_accesses NOT NULL (INV-001: health is
    access-scoped);
  - `health_score` smallint nullable; `capability_score` smallint nullable
    (IMPROVE#1: strictly separate);
  - `target_health` json nullable (`[{target, score}]`, §11.26);
  - `latency_percentiles` json nullable — `{p50,p95,p99,jitter_ms}` storage for #15;
  - `anonymity_tier` string nullable + `anonymity_classifier_version` string nullable
    (§11.7: tier is derived interpretation);
  - `health_formula_version` string NOT NULL (R6.6);
  - `fresh_until` timestamp nullable; `computed_at` timestamp nullable;
  - `timestamps`;
  UNIQUE `(tenant_id, access_id)`; INDEX `(tenant_id, fresh_until)`.
- Models `src/Models/{ProxySource,ProxyCapability,ProxyHealth}.php` — all use
  `BelongsToTenant`; casts to enums where applicable; no business logic.
- Factories `database/factories/{ProxySourceFactory,ProxyCapabilityFactory,ProxyHealthFactory}.php`.

## Conventions

- Capability/health rows are projections of future engines; keep models dumb
  (no evaluators in Stage 1).
- Every stored derived value carries its formula/classifier version (R6.6).

## Tests

`tests/Feature/Models/{ProxySourceTest,ProxyCapabilityTest,ProxyHealthTest}.php`:
- factory roundtrips; FK integrity (orphan access_id/endpoint_id rejected — negative case);
- health unique per `(tenant_id, access_id)` (negative duplicate case);
- cross-tenant invisibility on all three models + no-context throw
  (negative tenancy cases).

## Verify

```
timeout 120 ../../../vendor/bin/pest --testsuite Feature
timeout 120 ../../../vendor/bin/pest
```

## Out-of-scope

- Health/capability evaluation engines (Stages 4–5), TargetHealth computation,
  geo/reputation enrichment (#10/#11 — later stages).
