# T02 — Tenancy foundation: TenantContext + BelongsToTenant

Plan ref: §7 rows #23/IMPROVE#5, #1; plan §§4, 11.22 (defense-in-depth), INV-006.
Depends on: T01.

## Goal

Make tenant scoping impossible to bypass at the Eloquent level (isolation level 1
of §11.22): every domain model gets `tenant_id` from the authenticated context
only, every query is auto-scoped, queries without context fail loudly.

Tenant = platform user (`users.id`, auto-increment bigint), 1 user = 1 workspace.
No `proxy_workspaces` table in this stage: plan §11.21 marks `workspaces` as
platform-owned ("= users платформы, 1:1"). The reserved `role` field therefore
lives nowhere yet — revisit when a workspace table becomes necessary.

## Deliverables (file-by-file)

- `src/Tenancy/TenantContext.php` — final class, singleton registered by
  `ProxyOperationsServiceProvider` (`$this->app->scoped(TenantContext::class)`).
  Methods: `set(int $userId): void`, `id(): int` (throws
  `TenantNotResolvedException` when unset), `tryId(): ?int`, `forget(): void`.
- `src/Tenancy/TenantNotResolvedException.php` — `final class ... extends \RuntimeException`.
- `src/Models/Concerns/BelongsToTenant.php` — trait:
  - `bootBelongsToTenant()`: adds a global scope applying
    `where($table.'.tenant_id', TenantContext::id())`; if no tenant is set in the
    context, the scope throws `TenantNotResolvedException` (queries without an
    explicit authenticated context are forbidden — never fall back to unscoped).
  - `creating()` hook: force-fills `tenant_id` from `TenantContext::id()`;
    a `tenant_id` supplied via attributes/input is overwritten (INV-006 —
    client-provided tenant_id is never authoritative).
- Model conventions established here and reused by T03–T08 models:
  `tenant_id` NOT NULL column + index leading all composite indexes;
  child tables duplicate `tenant_id` even though derivable via FK (§11.22);
  `tenant_id` never in `$fillable`.

## Conventions

- The trait must stay framework-only code under `src/Models/Concerns/` — pure
  Domain contracts (Stage 0) must not depend on it.
- No repositories yet (later stages); the trait + scope IS the enforcement point.

## Tests

`tests/Feature/Tenancy/BelongsToTenantTest.php` (uses a throwaway migration +
test model defined inside the test file or `tests/Fixtures/`, so it does not
depend on T03+ tables):
- with context: model created → row has context tenant_id, even if caller passed
  a different `tenant_id` attribute (overwritten);
- query returns only current-tenant rows when rows of two tenants exist
  (negative cross-tenant case);
- query/create without context throws `TenantNotResolvedException`
  (negative case);
- `withoutGlobalScopes()` escape hatch exists but is asserted to be unused by
  module code later (arch-level note only).

## Verify

```
timeout 120 ../../../vendor/bin/pest --testsuite Feature
timeout 120 ../../../vendor/bin/pest
```

## Out-of-scope

- Postgres RLS (§11.22 level 3) — separate infra task, not Stage 1.
- Workspace resolution from initData/chat id (Stage 10 application layer).
