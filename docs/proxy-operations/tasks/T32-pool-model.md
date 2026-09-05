# T32 — Pool model: definitions + members

Source: `../plan.md` §§11.21, 11.25.
Depends on: T05 (ProxyAccess), T07 (health-scoped tables), T02 (tenancy).

## Scope

Durable pool entities: `proxy_pools` (tenant-owned definitions: STATIC /
DYNAMIC / HYBRID + predicate) and `proxy_pool_members` (projection rows —
NOT a second source of truth, §11.25). No materialization logic here
(T33), no selection (T35).

## Plan references

- §11.21 table map: `proxy_pools` (application layer owns), `proxy_pool_members`
  (projection, materializer owns, NOT NULL)
- §11.25: dynamic pool = projection with `materialization_version`,
  `generated_at`, `decision_log_id`; rebuild must be reproducible
- §11.22: `tenant_id` leading in every index; server-side scoping tests
  mandatory (negative cases included)

## Migrations

### `create_proxy_pools_table`

- `id` (ulid/uuid pk), `tenant_id` (NOT NULL, indexed first)
- `name` (string, unique per tenant), `kind` (string: `static|dynamic|hybrid`)
- `predicate` (nullable json — `PoolPredicate` DTO, required for dynamic/hybrid)
- `enabled` (bool, default true), `description` (text, nullable)
- `policy_version` (int, default 1 — predicate/policy lineage)
- timestamps; UNIQUE `(tenant_id, name)`; index `(tenant_id, enabled)`

### `create_proxy_pool_members_table`

- `id`, `tenant_id` (NOT NULL), `pool_id` (FK → proxy_pools, cascade)
- `access_id` (FK → proxy_accesses, cascade)
- `added_at`, `materialization_version` (nullable int — set by dynamic
  materializer; NULL for hand-picked static members)
- UNIQUE `(pool_id, access_id)`; index `(tenant_id, access_id)` —
  "which pools contain this access" queries

## Classes

### `src/Domain/Pool/PoolKind.php`

Enum: `Static`, `Dynamic`, `Hybrid` (TitleCase, string backed).

### `src/Domain/Pool/PoolPredicate.php`

`final readonly` DTO (JsonSerializable + `SCHEMA_VERSION = 1` +
`fromJsonV1`), conjunctive filter over accesses:

```php
public function __construct(
    public readonly ?array $states,          // list<AccessState->value>
    public readonly ?array $protocols,       // list<ProxyProtocol->value>
    public readonly ?array $tags,            // list<non-empty-string>
    public readonly ?float $minHealthScore,  // null = no floor
    public readonly ?bool $telegramUsableOnly,
    public readonly ?array $countries,       // list<ISO-3166 alpha-2, uppercase>
) {}
public function matches(PoolCandidateView $candidate): bool;
```

### `src/Domain/Pool/PoolCandidateView.php`

Readonly DTO projected from `ProxyAccess` + `ProxyHealth` (+ tags/country via
endpoint fields that exist in the schema — check `proxy_accesses` /
`proxy_health` columns; use what exists, do not invent columns). Pure
function from models: `PoolCandidateView::fromModels(ProxyAccess, ?ProxyHealth)`.

### `src/Models/ProxyPool.php`, `src/Models/ProxyPoolMember.php`

Eloquent models + `HasFactory`; tenant scoping via the module's established
pattern (see sibling models — replicate exactly, including any
tenant-identifying trait/casts). Mass-assignment casts for `predicate` JSON.

### `src/Audit/PoolRepository.php` (or sibling namespace consistent with module)

Application-service wrapper used by later tasks: `create`, `findForTenant`,
`membersOf`, `attachMember`, `detachMember`. All queries forced
tenant-scoped; rejects foreign-tenant access ids on attach.

### Factories

`ProxyPoolFactory`, `ProxyPoolMemberFactory` (linked access via existing
`ProxyAccess` factory).

## Tests

### `tests/Feature/Pools/PoolModelTest.php`

- Migration round-trip: create pool (all three kinds), member attach/detach
- UNIQUE constraints enforced (tenant+name; pool+access)
- `PoolPredicate` JSON round-trip; `matches()` truth table per field
  (null = pass-through)
- `PoolCandidateView::fromModels` maps real model fields

### `tests/Feature/Pools/PoolTenancyTest.php`

- Negative scoping: attaching a foreign-tenant access id throws; queries with
  wrong tenant return empty; no cross-tenant leak via pool_id either

## Verify command

```bash
timeout 120 ../../../vendor/bin/pest --filter=PoolModel
timeout 120 ../../../vendor/bin/pest --filter=PoolTenancy
```
