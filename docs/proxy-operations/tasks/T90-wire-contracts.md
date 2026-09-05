# T90 — Wire contracts V1: AuditTask / AuditResult / ProbeExecution

Plan ref: task #90; plan §§11.9–11.10, §11.30, §11.39 пп.5–6, 18. Depends on: T89.

## Goal

Language-neutral, versioned wire contracts between Scheduler → Worker → Runner.
Worker is replaceable by a non-PHP implementation (INV-016), so these DTOs are
the contract — design them wire-first.

## Create under `src/Wire/`

- `Wire\AuditTaskV1` (immutable execution snapshot): taskId, jobId, attemptId,
  tenantId (**metadata only** — never used for authorization inside worker,
  INV-006), accessRef (endpoint identity + credential fingerprint from
  `Domain\Identity`), credential delivery (exactly one of:
  `SealedCredentialPayload` | `CredentialReference`), probe list (ProbeExecutionSpecV1[]),
  policySnapshotVersion, deadline ISO-8601, maxAttempts.
- `Wire\CredentialReference`: opaque handle resolved by the worker's local
  unseal service — worker never receives KEK/DEK material (INV-004).
- `Wire\SealedCredentialPayload`: ciphertext envelope (alg id + ciphertext +
  nonce); plaintext never exists in the contract.
- `Wire\ProbeExecutionSpecV1`: probe type, profile, target descriptor,
  per-probe timeout/output-byte limits (minimal ProbeInput source per §11.39 п.5).
- `Wire\AuditResultV1`: taskId, attemptId, status (`Completed|Failed|TimedOut`),
  observations (raw, tenant-neutral), execution failures (TOOL_* descriptors
  from `Domain\Failure`), timings, checkerNodeId. TOOL_* results go to the
  execution-failure collection only — never into observations (INV-014/015).
- `Wire\JobRef`: jobId/attemptId/taskId triple (Job → Attempt → TaskDelivery).

All DTOs follow global DTO style (SCHEMA_VERSION, fromJson with version match).
JSON must contain no domain objects beyond ids/identities.

## Tests

- Full round-trip serialize/deserialize for every DTO.
- `fromJson` rejects unknown schemaVersion.
- AuditTaskV1 validation: exactly one credential delivery mode; deadline present.
- AuditResultV1 cannot carry TOOL_* codes as observations (asserted in tests).
