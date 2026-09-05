# T29 — CachePolicy + ProbeCache infrastructure

Source: `../plan.md` §§11.7, 11.14, 11.19–11.20, R6.2/R6.4.
Depends on: T92 (`ProbeCacheKeyV3`, `SharedCacheValue`, `SharedCacheValueKind`).

## Scope

The shared raw-probe cache backend: policy (TTL per value kind, negative
caching) and the cache service itself. Redis-backed (Laravel cache store) —
raw probe observations are runtime data, Postgres is not involved. The cache
is keyed by `ProbeCacheKeyV3::toHash()` and stores `SharedCacheValue` DTOs
only — the allowlist/enforcement already lives in `SharedCacheValue`
(INV-005: raw evidence only, no tenant interpretation, no secrets).

## Plan references

- §11.7/R6.2: key identity = `ProbeCacheKeyV3`; `checker_region` is NOT a
  key discriminator; secrets never enter key or value
- §11.7/R6.4: value = safe raw evidence only; liveness invariant — full body
  fetched, body never cached (bodies are not scalar → already rejected)
- §11.14: shared cache is ON from this stage; cross-tenant sharing is the
  point (same endpoint+credential fingerprint → one raw observation)
- §11.20 (operational events): `CachePoisoningSuspected` is out of scope here
  (no poisoning-detection engine yet); hit/miss metrics are in T30

## Classes to create

### `src/Audit/CachePolicy.php`

`final readonly` DTO built from config `proxy-operations.audit.cache`:

```php
final readonly class CachePolicy
{
    /**
     * @param  array<string, int>  $ttlByKind  SharedCacheValueKind value => TTL seconds.
     */
    public function __construct(
        public readonly bool $enabled,
        public readonly int $negativeTtlSeconds,
        public readonly array $ttlByKind,
        public readonly int $defaultTtlSeconds,
    ) {}

    public function ttlForKind(SharedCacheValueKind $kind): int;
    public static function fromConfig(array $config): self;
}
```

Config section `audit.cache` (additive in `config/proxy-operations.php`):

```php
'cache' => [
    'enabled' => true,           // shared cache ON at stage 6 (§11.14)
    'key_prefix' => 'proxy:probe-cache:',
    'default_ttl_seconds' => 1800,
    'negative_ttl_seconds' => 120,
    'ttl_by_kind' => [
        // SharedCacheValueKind value => seconds
        'http_measurement' => 1800,
        'timing_measurement' => 900,
        'exit_ip_observation' => 3600,
        'dns_observation' => 3600,
        'marker_result' => 1800,
        'anonymity_header_flags' => 3600,
        'negative_probe_result' => 120,
    ],
],
```

### `SharedCacheValueKind::NegativeProbeResult`

Additive enum case `NegativeProbeResult = 'negative_probe_result'` — a cached
"this probe failed recently" marker (negative caching). Payload allowlist for
this kind: `failure_code` (string), `checked_at_ms` (int). This is a raw
operational fact, not tenant interpretation — it does not violate INV-005.
Extend the existing unit test for the new kind.

### `src/Audit/ProbeCacheEntry.php`

Readonly DTO pairing a `SharedCacheValue` with metadata needed by consumers:

```php
final readonly class ProbeCacheEntry
{
    public function __construct(
        public readonly SharedCacheValue $value,
        public readonly int $cachedAtMs,
        public readonly int $ageSeconds, // resolved at read time
    ) {}
    // jsonSerialize/fromJson with SCHEMA_VERSION = 1
}
```

### `src/Audit/ProbeCache.php` (interface)

```php
interface ProbeCache
{
    public function get(ProbeCacheKeyV3 $key, SharedCacheValueKind $kind): ?ProbeCacheEntry;
    public function put(ProbeCacheKeyV3 $key, SharedCacheValue $value): void;
    public function putNegative(ProbeCacheKeyV3 $key, string $failureCode): void;
    public function getNegative(ProbeCacheKeyV3 $key): ?string; // failure code or null
}
```

Storage model: one Redis hash string per (key hash, kind) storing the
`ProbeCacheEntry` JSON with the TTL from `CachePolicy::ttlForKind()`;
negative entries stored under a dedicated kind slot with the negative TTL.

### `src/Audit/LaravelCacheProbeCache.php`

Implementation over `Illuminate\Support\Facades\Cache` (same pattern as
`CacheJobPlacementDedup`): cache key = `key_prefix` + `toHash()` + `:` + kind
value. All failures of the cache store are swallowed and treated as a miss —
the cache must never break a probe run (raw observations remain the source of
truth; a cold cache only costs egress). Lazy connection per INV-009.

## Tests

### `tests/Feature/Audit/CachePolicyTest.php`

- `fromConfig` maps TTLs; unknown kind → default TTL
- Disabled policy short-circuits (returns nulls without touching the store)

### `tests/Feature/Audit/ProbeCacheTest.php`

- put → get round-trips entry (same key + kind); age grows
- different `ProbeCacheKeyV3` field → miss (esp. toolSemanticsVersion,
  credentialFingerprint)
- different kind under the same key → miss
- negative: putNegative → getNegative returns code; TTL expiry (travel or
  array-store TTL) → null
- corrupt payload in store → treated as miss, no exception
- store outage (failing store fake) → get returns null, put does not throw

## Verify command

```bash
timeout 120 ../../../vendor/bin/pest --filter=CachePolicy
timeout 120 ../../../vendor/bin/pest --filter=ProbeCache
```
