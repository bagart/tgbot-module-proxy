# T05 — ProxyAccess: operational identity owning health/lifecycle/quarantine (migration + model + factory)

Plan ref: §7 rows #3, #5; plan §§5, 11.2, 11.6, 11.21 (`proxy_accesses`),
§11.35 п.1, §11.37 R6.1; INV-001/002. Depends on: T03, T04.

## Goal

`ProxyAccess` = endpoint + credential + ALL lifecycle state. This is the table
INV-001 protects: state/testability/quarantine live here, never on
ProxyEndpoint (INV-002). Maps directly onto Stage-0 contracts in
`src/Domain/Lifecycle/`.

## Deliverables (file-by-file)

- `database/migrations/2026_08_27_000003_create_proxy_accesses_table.php`
  columns:
  - `id` uuid PK;
  - `tenant_id` foreignId constrained, NOT NULL (duplicated per §11.22);
  - `endpoint_id` foreignId → `proxy_endpoints`, NOT NULL;
  - `credential_id` foreignId nullable → `proxy_credentials`
    (NULL = credential-free access);
  - `access_identity_hash` string(64) NOT NULL — from
    `Domain\Identity\AccessIdentity` (endpoint identity + credential fingerprint);
  - `state` string NOT NULL default `new` (`Domain\Lifecycle\AccessState`);
  - `testability_status` string NOT NULL default `testable`
    (`Domain\Lifecycle\TestabilityStatus`);
  - `quarantine_status` string NOT NULL default `none` +
    `quarantine_reason` string nullable (`Domain\Lifecycle\QuarantineStatus`);
  - `consecutive_failures` int NOT NULL default 0 (hysteresis input);
  - `last_checked_at`, `state_changed_at` timestamps nullable;
  - Telegram freshness block (§11.35 п.10): `telegram_connectivity` bool nullable,
    `telegram_usable` bool nullable, `telegram_checked_at`/`telegram_fresh_until`
    timestamps nullable, `telegram_evidence_version` string nullable;
  - `timestamps`;
  indexes: UNIQUE `(tenant_id, access_identity_hash)`;
  INDEX `(tenant_id, state)`; INDEX `(endpoint_id)`.
- `src/Models/ProxyAccess.php` — `HasFactory`, `HasUuids`, `BelongsToTenant`.
  State columns are cast to the Stage-0 enums (`AccessState`,
  `TestabilityStatus`, `QuarantineStatus`). Helpers:
  `recordTransition(AccessState $to, CauseKind $cause): void`-style mutator that
  delegates legality to `Domain\Lifecycle\AccessStateMachine` and stamps
  `state_changed_at`; `telegramUsableNow(): ?bool` applying freshness
  (`telegram_fresh_until` expired → not usable, §11.35 п.10).
  Relationships: `endpoint()`, `credential()`.
- `database/factories/ProxyAccessFactory.php` — states `working()`,
  `degraded()`, `dead()`, `quarantined(reason)`, `credentialFree()`.

## Conventions

- No health_score/capability_score columns here — those are derived and live in
  T06 tables; this table is canonical lifecycle only.
- Enum casts must use the existing Domain enums — do not re-declare states.

## Tests

`tests/Feature/Models/ProxyAccessTest.php`:
- unique `(tenant_id, access_identity_hash)` enforced (negative duplicate case);
- same AccessIdentity creatable in another tenant (cross-tenancy positive);
- illegal lifecycle transition rejected via `AccessStateMachine` even at model level;
- stale `telegram_fresh_until` flips `telegramUsableNow()` to null/false
  (freshness negative case);
- cross-tenant invisibility + no-context throw (negative tenancy cases).

## Verify

```
timeout 120 ../../../vendor/bin/pest --testsuite Feature
timeout 120 ../../../vendor/bin/pest
```

## Out-of-scope

- Health engine / transitions triggered from AuditResult (Stage 5).
- Leases, verified projection (Stages 7/9).
