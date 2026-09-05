# T34 — ProxyLease: state, Redis lock, lifecycle

Source: `../plan.md` §11.24, §10.12 пп.6, §11.9, §11.20.
Depends on: T02 (tenancy), T05 (ProxyAccess), T16 (Redis wiring pattern).

## Scope

One active lease per AccessIdentity with crash recovery: Postgres holds
lease state (truth), Redis holds the atomic lock (concurrency primitive).
Acquire / renew (heartbeat) / release; expired leases are reclaimable by the
reaper (T35). `LeaseAcquired` / `LeaseReleased` domain events through the
T28 outbox.

## Plan references

- §11.24: acquire flow = candidate → atomic Redis `SET NX PX` → Postgres
  state (`holder`, `expires_at`, `purpose`) → runtime lease DTO; TTL default
  300s (§10.12 п.6); holder must renew via heartbeat; one active lease per
  AccessIdentity (`UNIQUE(active_lease, access_id)` semantics)
- §11.24: Redis loss → leases considered lost; Postgres state is the
  reconciliation source on recovery
- §11.9: lease is created by the application layer; Redis lock is only a
  concurrency primitive
- §11.22: everything tenant-scoped, negative tests mandatory

## Migrations

### `create_proxy_leases_table`

- `id`, `tenant_id` (NOT NULL), `access_id` (FK → proxy_accesses, cascade)
- `holder` (string — consumer identity token, e.g. ULID per consumer)
- `purpose` (string, default `session`)
- `state` (string: `active|released|expired|stolen`)
- `acquired_at`, `expires_at`, `released_at` (nullable), `renewals` (int default 0)
- `last_lease_event` (nullable json — last transition context, pattern from
  proxy_accesses)
- timestamps
- UNIQUE partial-like: one ACTIVE lease per access — implement as
  UNIQUE `(access_id, active_marker)` where `active_marker` is a generated
  column/attribute that is 1 when `state='active'` else NULL (portable
  across Postgres/SQLite: use a plain nullable `active_marker` int column
  maintained by the model/service, UNIQUE `(access_id, active_marker)`)
- index `(tenant_id, holder)`, index `(tenant_id, expires_at)` (reaper scan)

## Classes

### `src/Domain/Lease/LeaseState.php`

Enum: `Active`, `Released`, `Expired`, `Stolen` (TitleCase, string backed).

### `src/Domain/Lease/ProxyLeaseDto.php`

Readonly runtime lease handed to consumers (JsonSerializable +
`SCHEMA_VERSION = 1` + `fromJsonV1`): leaseId, accessId, tenantId, holder,
purpose, acquiredAtMs, expiresAtMs. No credentials, no endpoint secrets —
endpoint/credential access goes through access id (consumer resolves via
application layer, INV-013).

### `src/Audit/LeaseLockStore.php` (interface) + `LaravelLeaseLockStore.php`

```php
interface LeaseLockStore
{
    public function acquire(string $lockKey, string $holder, int $ttlMs): bool;
    public function renew(string $lockKey, string $holder, int $ttlMs): bool;
    public function release(string $lockKey, string $holder): void;
}
```

Implementation over `Cache::lock`/`Cache::add` semantics (same facade pattern
as T29): atomic owner-checked operations; store failures surface as
`acquire=false` (fail-closed — no lock, no lease).

### `src/Audit/LeaseService.php`

```php
final class LeaseService
{
    public function acquire(ProxyAccess $access, string $holder, string $purpose = 'session'): ?ProxyLeaseDto;
    public function renew(ProxyLeaseDto $lease): ?ProxyLeaseDto; // heartbeat; expired → null
    public function release(ProxyLeaseDto $lease): void;
    public function reclaimExpired(int $limit): int; // reaper core; T35 schedules it
}
```

Semantics:

- `acquire`: Redis lock `SET NX` on `lease:lock:{accessId}` → in ONE Postgres
  transaction create row `state=active` with `expires_at = now + ttl`; a
  concurrent active row violates the unique constraint → release the Redis
  lock, return null. Lock/DB ordering is lock-then-commit; on any failure
  the Redis lock is released (no orphaned lock without state)
- `renew`: holder-verified (`holder` + `state=active` + not expired);
  extends `expires_at`, bumps `renewals`, re-arms the Redis lock TTL;
  expired leases cannot be renewed (→ null; reaper territory)
- `release`: holder-verified; Postgres `state=released` + `released_at`
  FIRST, then Redis unlock (state truth survives a Redis crash)
- `reclaimExpired`: batch-scan `(tenant-scoped`? — reaper is system-level:
  scan `state=active AND expires_at < now`, mark `expired`, clear
  `active_marker`, release Redis locks best-effort; return count
- Redis unavailable → acquire returns null (fail-closed), release/renew
  still succeed on the Postgres side; loss of Redis never corrupts lease
  state (§11.24 reconciliation note)
- `LeaseAcquired` / `LeaseReleased` events recorded after commit (payload:
  lease id, access id, holder, purpose — no secrets)

### Config

`config/proxy-operations.php`: new `audit.leases` section:
`ttl_seconds` (300), `renew_margin_seconds` (60 — renew must happen while
more than this remains), `reaper_batch_size` (200).

## Tests

### `tests/Feature/Leases/LeaseServiceTest.php`

- acquire → active row + DTO; second acquire for the same access → null
  (unique constraint path), Redis lock not leaked
- renew extends expiry + bumps renewals; renew after expiry → null;
  renew by wrong holder → null (and no state change)
- release → released row, Redis lock freed, new acquire succeeds
- reaper: expired active rows → `expired`, lock freed, access acquirable again
- events: LeaseAcquired / LeaseReleased recorded post-commit; no event on
  failed acquire
- Redis-store failure injection: acquire → null with NO Postgres row;
  release still completes on DB side; state consistent (§11.24)

### `tests/Feature/Leases/LeaseTenancyTest.php`

- foreign-tenant access acquire rejected; holder queries scoped to tenant;
  DTO carries tenant id

## Verify command

```bash
timeout 180 ../../../vendor/bin/pest --filter=Lease
```
