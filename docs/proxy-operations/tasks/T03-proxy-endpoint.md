# T03 — ProxyEndpoint: central inventory entity (migration + model + factory)

Plan ref: §7 rows #1, #3; plan §§5, 11.2, 11.21 (`proxy_endpoints`), INV-002.
Depends on: T02.

## Goal

Persist the central `ProxyEndpoint` network identity (scheme/host/port) as the
single source of truth for inventory. Endpoint never carries canonical health
(INV-002 — health belongs to ProxyAccess, see T05). Non-VPN scheme rejection is
parser-side (Stage 2); this model only accepts `ProxyProtocol` values.

## Deliverables (file-by-file)

- `database/migrations/2026_08_27_000001_create_proxy_endpoints_table.php`
  columns:
  - `id` uuid PK (`HasUuids`);
  - `tenant_id` foreignId constrained to `users`, NOT NULL;
  - `protocol` string NOT NULL (stored value of `BAGArt\ProxyOperations\Domain\Identity\ProxyProtocol`);
  - `host` string NOT NULL (as imported, original form);
  - `port` smallint NOT NULL;
  - `canonical_host` string NOT NULL (output of `Domain\Identity\EndpointCanonicalizer`:
    lowercase/IDNA/IPv6 RFC 5952/trailing-dot removal);
  - `endpoint_identity_hash` string(64) NOT NULL — deterministic hash of the
    canonical `EndpointIdentity` (tenant-independent value, tenant-scoped uniqueness);
  - `comment` string nullable;
  - `timestamps`;
  indexes: UNIQUE `(tenant_id, endpoint_identity_hash)`; INDEX `(tenant_id, protocol)`.
- `src/Models/ProxyEndpoint.php` — uses `HasFactory`, `HasUuids`,
  `BelongsToTenant` (T02). Maps to Stage-0 contract
  `Domain\Identity\EndpointIdentity`: provide
  `identity(): EndpointIdentity` and static
  `fromIdentity(EndpointIdentity $i): self`-style helpers so callers never
  hand-roll canonicalization. `$hidden = ['...']` n/a; no secrets here.
  Relationships: `credentials()` hasMany-through not used — link is via
  `ProxyAccess` (T05); add `accesses()` hasMany now.
- `database/factories/ProxyEndpointFactory.php` — namespace
  `BAGArt\ProxyOperations\Database\Factories`; definition with valid defaults
  (protocol socks5, host from faker ipv4, port 1080); compute canonical host +
  identity hash via the real `EndpointCanonicalizer`/`EndpointIdentity`
  (never fake hashes). State: `forTenant()` unnecessary (context-driven), but add
  `http()`, `mtproto()` protocol states for reuse.

## Conventions

- Migration timestamp prefix `2026_08_27_` sequence across Stage-1 tasks keeps
  ordering deterministic.
- Uniqueness of endpoint within workspace = `(tenant_id, endpoint_identity_hash)`
  (§11.35 п.2: endpoints are tenant-owned, no global registry).

## Tests

`tests/Feature/Models/ProxyEndpointTest.php`:
- factory creates row; canonical hash matches `EndpointCanonicalizer` output for
  equivalent inputs (`1.2.3.4`, trailing dot, uppercase);
- unique constraint violated on duplicate identity in same tenant (negative case);
- same identity hash allowed in a second tenant (cross-tenant case);
- queries without TenantContext throw (negative tenancy case);
- `identity()` returns an `EndpointIdentity` equal to the canonicalized input.

## Verify

```
timeout 120 ../../../vendor/bin/pest --testsuite Feature
timeout 120 ../../../vendor/bin/pest
```

## Out-of-scope

- Parsing/import flows (Stage 2), MTProto secret handling (T04/T08),
  health/capability fields on endpoint (forbidden by INV-002).
