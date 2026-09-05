# T25 — Task delivery over Redis Streams

Source: `../plan.md` §§11.9, 11.19, 11.30, 11.35 пп.5–6, 11.37 R6.5/R6.7.

## Scope

Bridges the application layer to the worker: builds `AuditTaskV1` wire
messages from Job + Attempt + Access (delivery planning), enqueues them to
Redis Streams, and consumes `AuditResultV1` messages back. Includes the
retry/DLQ path. Application layer only — the worker side (control/execution
plane) already exists from T18; this task is the producer/consumer on the
platform side.

## Dependencies

| Depends on | Reason |
|---|---|
| T23 | Job/Attempt models |
| T24 | JobStarter (jobs exist with snapshots) |
| T90 | `AuditTaskV1`, `AuditResultV1`, `SealedCredentialPayload`, `JobRef` |
| T09 | `CredentialEncryptor` — sealed payload creation (R6.5: application decrypts credential, seals with runtime key) |
| T16 | `CredentialSealer`/unseal primitives |
| T92 | `TaskDeliveryIdempotencyKey` |
| T18 | Redis Streams client wiring pattern (`php-async-kernel-client-redis`) |

## Plan references

- §11.9: worker writes results to Redis; domain changes go through the
  application layer (ingestion is T26 — here only delivery/receipt)
- §11.19: worker-results idempotency = `task_id + attempt_id`, storage in
  attempts unique constraint (T23)
- §11.30: wire contracts are language-independent; tenant_id is metadata
  only in the task (§11.35 п.6) — used for logs/DLQ routing, never authz
- §11.35 п.5: sealed payload TTL = job TTL; worker never sees KEK
- §11.35 п.18/R6.7: no domain side-effects on delivery — pure Redis runtime

## Classes to create

### `src/Audit/AuditTaskFactory.php`

```php
final class AuditTaskFactory
{
    public function __construct(
        private readonly CredentialSealer $sealer, // runtime-key sealing, TTL from config
    ) {}

    /** Build one wire task for one attempt. */
    public function build(
        ProxyAuditJob $job,
        ProxyAuditAttempt $attempt,
        ProxyAccess $access,
    ): AuditTaskV1
}
```

- Fills `endpoint_snapshot` from `ProxyEndpoint`, `credential_execution`
  from sealed `SealedCredentialPayload` (unsealed from DEK in-process, then
  re-sealed with runtime key; plaintext never leaves the method — INV-013),
  `policy_snapshot_id`, `probe_profile` + version, `target_set` versions
  from `AuditPolicySnapshot`
- `task_id` = ULID; `attempt_id` from the attempt row

### `src/Audit/AuditDeliveryQueue.php` (interface)

```php
interface AuditDeliveryQueue
{
    public function enqueue(AuditTaskV1 $task): void;
    /** @return list<AuditResultV1> */
    public function consumeResults(int $max): array;
    public function enqueueResult(AuditResultV1 $result): void; // worker-side push, test/dlq use
}
```

### `src/Audit/RedisStreamsAuditDeliveryQueue.php`

Implementation over `php-async-kernel-client-redis` streams (lazy connect,
INV-009). Streams: `proxy:audit:tasks` (consumer groups) and
`proxy:audit:results`. Config keys in `config/proxy-operations.php` under
`audit.delivery`.

### `src/Audit/DeliveryDispatcher.php`

For a `pending` job: creates attempt (attempt_no = max+1), builds a task per
access, persists attempt ids, enqueues, marks job `queued` and attempts
`delivered`. Wrapped in a DB transaction for the Postgres part; enqueue
after commit (at-least-once; consumer dedups by `task_id + attempt_id`).

### `src/Audit/DeliveryRetryPolicy.php`

Retry budgets from `AuditPolicySnapshot.retryPolicy`: max attempts, backoff
base (24h→72h per §11.27 applies to scheduler reschedule — here per-attempt
delivery retries: small budget, e.g. 3). On budget exhaustion → DLQ entry
(`DeadLetterEntry`-shaped readonly DTO, `src/Audit/AuditDeadLetter.php`,
JsonSerializable + SCHEMA_VERSION) + attempt `failed`.

## Tests

### `tests/Feature/Audit/AuditTaskFactoryTest.php`

- Task built with all snapshot fields; access/endpoint data correct
- Credential sealed payload round-trips through the runtime unsealer; plaintext
  credential never appears in task JSON or exceptions (secret-masking test)
- Sealed payload TTL set from config

### `tests/Feature/Audit/DeliveryDispatcherTest.php`

- Pending job → attempts created, tasks enqueued, statuses transition
- Redis unavailability → Postgres state stays consistent, retry safe
  (at-least-once: duplicate task with same ids does not create a second attempt)
- Retry: failed attempt → new attempt (job unchanged, §11.37 R6.7)
- Budget exhausted → DLQ entry + failed attempt, no further deliveries
- tenant_id in task payload matches job tenant (metadata only)

Unit tests use an in-memory `AuditDeliveryQueue` fake; the Redis streams
implementation gets a contract-conformance test with the real client
skipped when no Redis (consistent with T18 daemon tests).

## Verify command

```bash
timeout 120 ../../../vendor/bin/pest --filter=AuditTaskFactory
timeout 120 ../../../vendor/bin/pest --filter=DeliveryDispatcher
```
