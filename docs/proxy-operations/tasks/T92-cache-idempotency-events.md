# T92 — ProbeCacheKey v3 + idempotency split + event envelope

Plan ref: task #92; plan §§11.7, 11.19–11.20, §11.39 п.12, R6.2/R6.4.
Depends on: T91 (tool_semantics_version type). Safe to run in parallel with T93.

## Goal

Deterministic raw-probe cache identity including execution-tool semantics, plus
idempotency and event contracts for the audit pipeline.

## Create under `src/Domain/Cache/`

- `ProbeCacheKeyV3` readonly DTO with fields: endpointIdentity,
  credentialFingerprint, checkerNodeId, egressIdentity, judgeSetVersion,
  telegramDcSetVersion, probeProfileVersion, probeSemanticsVersion,
  **toolSemanticsVersion**. Methods: stable `toString()` (ordered, escaped),
  `toHash()` sha256, JSON round-trip. No build metadata / git SHAs
  (cache fragmentation rule, INV-017).
- `SharedCacheValue`: wraps a cached raw observation; construction validates an
  explicit allowlist of value kinds (raw measurements only). Any field that
  looks like tenant interpretation (health/score/lifecycle/verified flags)
  is rejected at construction — shared cache never carries tenant semantics
  (INV-005).
- Idempotency split:
  - `JobIdempotencyKey` (import/trigger level — dedupes job creation),
  - `TaskDeliveryIdempotencyKey` (worker delivery level — dedupes task
    processing). Distinct types, distinct scopes (plan R5/R6 split).
- `EventEnvelope` readonly DTO: eventId (uuid), eventType, occurredAt,
  tenantId, aggregateRef, schemaVersion, payload array;
  `SequenceNumber` value object + `EventOrderingPolicy` (per-aggregate strict
  ordering, transactional outbox note per plan §11.20).

## Tests

- Key stability: same fields → same hash regardless of construction order;
  any field change → different hash (esp. toolSemanticsVersion).
- SharedCacheValue rejects tenant-interpretation payloads.
- Envelope round-trip + version rejection; ordering policy unit cases.
