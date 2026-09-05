# T19 — JudgeProvider

Source: `../plan.md` §§11.8, 11.17, 11.35 п.8, §11.39 п.19.

## Scope

Contract and implementation for judge resolution from `JudgeSetSnapshot` during
probe execution. The ProbeExecutor needs a list of judges to include in
judge-dependent probes (http_liveness, header_marker, anonymity_headers). This
task supplies that list, respecting the profile's judge count and rotation
strategy.

## Dependencies

| Depends on | Reason |
|---|---|
| T93 | `JudgeSetSnapshot`, `JudgeDescriptor`, `JudgeTrustTier` DTOs |

## Plan references

- §11.8: JudgeDefinition shape (url, region, protocol, capabilities, rateLimit,
  trustTier)
- §11.17: Profile table — light=1 judge (rotation), standard=2–3, deep=all
  available, telegram=DC-focused
- §11.35 п.8: JudgeSetSnapshot is immutable, versioned; cache key uses version
- §11.39 п.19: PHP owns domain decisions; judge selection is a domain decision

## Classes to create

### `src/Checker/JudgeProvider.php` (interface)

```php
interface JudgeProvider
{
    /** Select judges for a probe type from the given snapshot. */
    public function select(
        JudgeSetSnapshot $snapshot,
        ProbeType $probeType,
        int $count,
    ): list<JudgeDescriptor>;
}
```

### `src/Checker/JudgeSelectionStrategy.php` (enum)

```php
enum JudgeSelectionStrategy: string
{
    case RoundRobin = 'round_robin';
    case All = 'all';
    case Random = 'random';
}
```

### `src/Checker/JudgeSetProvider.php` (implementation)

```php
final readonly class JudgeSetProvider implements JudgeProvider
```

Responsibilities:
- Filter judges by capability match for the requested `ProbeType`
- Apply selection strategy: `All` for deep profile, `RoundRobin` for
  standard/light (keeps a monotonic counter per `(snapshotId, probeType)`),
  `Random` for DC-focused (telegram profile)
- Respect `rateLimitPerMinute` — skip judges whose budget is exhausted
  (in-memory sliding window, per-provider instance)
- Return empty list when snapshot has no matching judges (caller handles
  `JUDGE_UNAVAILABLE`)

### `src/Checker/JudgeBudgetTracker.php` (internal)

```php
final class JudgeBudgetTracker
```

Tracks per-judge minute-window request counts for rate-limit enforcement.
Sliding window with 60-second TTL buckets. Pure in-memory; no Redis needed
(worker-scoped, INV-009).

## Tests

### `tests/Unit/Checker/JudgeSetProviderTest.php`

- Filters judges by probe-type capability match
- RoundRobin rotates across calls with the same snapshot+probeType
- All strategy returns every matching judge
- Random strategy returns a shuffled subset
- Empty snapshot → empty list (no exception)
- Rate-limited judge is skipped when budget exhausted
- JudgeTrustTier ordering: higher trust preferred when count < available

### `tests/Unit/Checker/JudgeBudgetTrackerTest.php`

- First request within budget → allowed
- Burst exceeding rateLimitPerMinute → blocked
- Window slide: old requests expire, budget restored
- Multiple judges tracked independently

## Verify command

```bash
timeout 120 ../../../vendor/bin/pest --filter=JudgeSetProvider
timeout 120 ../../../vendor/bin/pest --filter=JudgeBudgetTracker
```
