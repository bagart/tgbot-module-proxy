# T27 — Health evaluation + lifecycle transitions

Source: `../plan.md` §§11.6, 11.7, 11.35 пп.1,9–11, §11.37 R6.3/R6.6.

## Scope

Implementation of `HealthEvaluator` (interface introduced in T26):
per-dimension health evaluation from evidence, anti-flap hysteresis,
capability-aware lifecycle transitions on `ProxyAccess`, health persistence
to `proxy_health` (access-scoped, T06), and telegram freshness
(`telegram_usable` derived from last result + freshness policy).

## Dependencies

| Depends on | Reason |
|---|---|
| T26 | `HealthEvaluator` contract + ingestion pipeline |
| T89 | `AccessStateMachine`, `HysteresisPolicy`, `HealthSignal`, evidence types, `FreshnessAwareEligibilityPolicy` |
| T06 | `ProxyHealth` model (access-scoped) |
| T05 | `ProxyAccess` (telegram freshness fields) |

## Plan references

- §11.6 / §11.35 п.9: lifecycle on Access; evidence is NOT a linear ladder —
  dimension-specific evidence with applicability; DEAD + light TCP OK does
  NOT jump to WORKING; MTProto handshake + TG connectivity suffices (HTTP/UDP N/A)
- §11.35 п.10: `telegram_usable` freshness — expired flag never reported true
- §11.29 (IMPROVE#11): anti-flap hysteresis
- R6.6: store `health_formula_version` next to derived values

## Classes to create

### `src/Audit/HealthEvaluator.php` (contract from T26)

```php
interface HealthEvaluator
{
    /**
     * Evaluate evidence for one access; persists health + performs the
     * lifecycle transition. Must be callable inside the ingestion transaction.
     * @param list<DimensionEvidence> $evidence
     */
    public function evaluate(ProxyAccess $access, array $evidence): HealthEvaluation;
}
```

### `src/Audit/HealthEvaluation.php`

Readonly DTO: per-dimension signals, resulting `HealthSignal`, `AccessState`
before/after (null if unchanged), formula version.

### `src/Audit/DimensionalHealthEvaluator.php`

Implementation of `HealthEvaluator`:
1. Group evidence by `EvidenceType`; evaluate each dimension to a
   `HealthSignal` (pass/fail/not-applicable) using applicability rules
2. Aggregate to a global state decision via `AccessStateMachine` (T89) —
   only capability-aware paths (no DEAD→WORKING jumps)
3. Apply `HysteresisPolicy` (T89): a transition fires only after N
   consecutive agreeing signals per dimension (config: hysteresis thresholds)
4. Persist/refresh the access-scoped `ProxyHealth` row (scores, dimensions,
   `health_formula_version` from config)
5. On state change: update `ProxyAccess` lifecycle fields, update telegram
   freshness columns when TelegramEvidence present
   (`telegram_checked_at`, `telegram_fresh_until`, `telegram_evidence_version`),
   and return the `LifecycleEvent`-shaped data to T26's recorder via
   `HealthEvaluation`

Config: `config/proxy-operations.php` → `audit.health` (formula version,
hysteresis N per direction, telegram freshness TTL).

## Tests

### `tests/Feature/Audit/DimensionalHealthEvaluatorTest.php`

- Full liveness+transport evidence pass on UNKNOWN access → WORKING transition
- DEAD access + only TcpEvidence pass → stays DEAD (no ladder jump)
- MTProto access: HTTP/UDP N/A + handshake ok + TG connectivity ok → WORKING
- Hysteresis: single failing signal below threshold → no transition; N
  consecutive → transition fires
- Repeated evaluation with same passing evidence → no duplicate events
- Health row written with formula_version; access-scoped (negative
  tenant-scoping check)
- telegram_usable: fresh successful TG evidence → true; same evidence after
  freshness expiry → derived false (flag not reported true)
- Evidence conflicting across dimensions → per §11.6 rules (documented
  expected outcome in the test)

## Verify command

```bash
timeout 120 ../../../vendor/bin/pest --filter=DimensionalHealthEvaluator
```
