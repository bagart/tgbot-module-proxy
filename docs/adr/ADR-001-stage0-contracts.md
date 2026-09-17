# ADR-001 — Stage 0 Contract Decisions

Status: accepted
Date: 2026-08-26
Scope: Proxy Operations module, Stage 0 (tasks #87–#94)
Supersedes: none

The architectural model was frozen at plan §11.38 ("further changes only via
ADR"). This document consolidates the Stage 0 decisions into one place; each
section states the decision, a short rationale, and the authoritative plan
section. Executable enforcement of the invariants referenced here lives in
`tests/Arch/InvariantsTest.php` (INV-001…INV-020).

## 1. Identity model — canonical EndpointIdentity + CredentialFingerprint

**Decision.** The canonical identity of an auditable endpoint is
`EndpointIdentity` (scheme/host/port after full normalization: lowercase
hostname, IDNA, RFC 5952 IPv6, default-port handling, trailing-dot removal,
canonical credential representation). Credentials are identified by
`CredentialFingerprint = HMAC-SHA256(system-secret, canonical credential
payload)`, globally deterministic and deliberately tenant-free. `AccessState`
is access-level, never endpoint-level.

**Rationale.** Identical proxies from different workspaces must produce the
same cache key or the shared cache stops being shared; different passwords
must produce different keys. Tenant must not enter domain identity for the
same reason. Renaming EndpointState → AccessState makes it impossible to
accidentally hang health off an endpoint.

Plan: §11.37 R6.1–R6.2, §§11.2–11.5. Invariants INV-001/002.

## 2. Failure taxonomy — responsibility axis (whose fault)

**Decision.** Every failure code carries a `FailureClass` answering "whose
fault": `Proxy | Target | Judge | Checker | Platform | Policy`. Classes
Checker and Platform form execution failures (`ExecutionFailure`), all others
form proxy observations (`ProxyFailure`). TOOL_* / REDIS_* / STORAGE_* codes
are Checker-class with `affectsHealth=false` enforced by the taxonomy.

**Rationale.** A checker crash is not an observation about a proxy. A single
axis with structural separation makes it impossible for infrastructure noise
to degrade proxy health, and gives retry/DLQ logic a clean predicate
(`isExecutionFailure()`).

Plan: §11.16–§11.17, §11.39 пп.13–14. Invariants INV-014/015.

## 3. Lifecycle — one state machine + orthogonal statuses

**Decision.** `AccessStateMachine` is the single lifecycle machine over
`AccessState`, driven by typed `TransitionRule`s and `CauseKind`; quarantine
(`QuarantineStatus`) and testability (`TestabilityStatus`) are orthogonal
statuses, not states; `HysteresisPolicy` gates flapping transitions.

**Rationale.** One machine plus orthogonal flags avoids state explosion
(state × quarantine × testability) and keeps every transition explainable:
cause and signal (`HealthSignal`) travel with the event
(`LifecycleEvent`).

Plan: §11.6, §11.35 пп.9–11. Invariants INV-001/002.

## 4. Wire contracts — versioned from day one

**Decision.** `AuditTaskV1`, `ProbeExecutionSpecV1`, `AuditResultV1` are
wire-level DTOs with public `SCHEMA_VERSION`, additive-only evolution, strict
`fromJson` version matching, and structural rejection of Checker/Platform
codes among observations. Every Wire class carries its own schema version;
enums carry none.

**Rationale.** The worker is not PHP-only forever (plan §11.39 п.18): a
go-worker or rust-worker must execute the same AuditTask tomorrow.
Versioned contracts are also what allows the projection to answer "why was
this verified then but not now" later.

Plan: §§11.9–11.10, §11.37 R6.6, §11.39 пп.13–14, 18. Invariants
INV-014/015/016.

## 5. Tool boundary — ProbeTool contract, registry allowlist, no shell

**Decision.** The domain sees only the `ProbeTool` interface
(`capabilities(): ToolCapabilities`, `execute(ProbeExecutionContext):
ProbeToolResult`). Inputs are minimal value objects
(`ProbeExecutionContext`, `ProbeSpec`); credentials cross as a
`CredentialChannel` delivery plan (stdin or file descriptor), never argv,
never secret material on the surface. External tools are resolved exclusively
by `tool_id` through the `ToolRegistry` allowlist to a fixed executable; no
arbitrary binary/argv execution exists at Stage 0 (no `proc_open`,
`shell_exec`, or backticks anywhere under `src/`).

**Rationale.** Binaries stay replaceable implementation details (curl today,
Go/Rust utility tomorrow) without touching the domain model. The allowlist
closes the `/execute {"binary": "/bin/bash"}` hole; the channel plan keeps
secrets out of process lists, crash dumps and traces while letting the runner
wire the actual pipe.

Plan: §11.39 пп.5–10, 15, 20. Invariants INV-011/012/013/018/019.

## 6. Cache key V3 — semantics-complete reproducibility identity

**Decision.** `ProbeCacheKeyV3` is the deterministic identity of a shared
raw-probe cache entry: endpoint identity, credential fingerprint,
checker_node_id, egress_identity, judge_set_version, telegram_dc_set_version,
probe_profile_version, probe_semantics_version and tool_semantics_version
(tool_name + tool_version + tool_protocol_version). `checker_region` is
metadata and excluded; no git SHAs or build identifiers participate.

**Rationale.** Every field that can change the raw observation result must be
in the key, or results get silently reused across meaningfully different
executions; build metadata would fragment the cache without changing results.
Semantically summarized tool version keeps checker v1/v2 distinguishable.

Plan: §11.7, §11.37 R6.2, §11.39 п.12. Invariant INV-017.

## 7. Idempotency split + event envelope

**Decision.** Two distinct idempotency keys with separate scopes and stores:
`JobIdempotencyKey` (import/trigger level, from the Application API
`Idempotency-Key` header or normalized import batch hash) and
`TaskDeliveryIdempotencyKey` (taskId+attemptId, backed by the attempts unique
constraint). All events travel as a readonly additive-only `EventEnvelope`
with UUID eventId and per-aggregate ordering via `SequenceNumber`
(`EventOrderingPolicy`); delivery is at-least-once via the transactional
outbox.

**Rationale.** Job triggers and worker deliveries are not interchangeable —
sharing a dedup namespace would swallow either retries or new work. At-least-once
delivery forces consumer-side idempotency instead of pretending exactly-once.

Plan: §11.19–§11.20, §11.37 R6.7–R6.8.

## 8. Snapshots — immutable system dictionaries with contract matrices

**Decision.** Judge sets (`JudgeSetSnapshot`), Telegram DCs
(`TelegramDcSet`) and audit policy (`AuditPolicySnapshot`) enter execution as
immutable snapshots carrying explicit versions; compatibility between the
platform and worker/tool manifests is declared by `ContractVersionMatrix`
entries rather than sniffed at runtime.

**Rationale.** Reproducibility and explainability require pinning what the
audit assumed (which judges, which DCs, which policy) at execution time;
version comparison against published manifests replaces scattered
`if ($version === '2.4')` checks.

Plan: §11.35 пп.7–8, §11.39 пп.10–11. Invariant INV-016 (versions travel
with derived data, R6.6).

## 9. SSRF policy split — three orthogonal policies

**Decision.** Outbound safety is enforced by three independent policies, not
one monolith: `JudgeConnectPolicy` (what may connect to judges),
`ProxyEndpointConnectPolicy` (connect-through rules incl. `max_redirects=0`
anti-trampoline) and `TargetFetchPolicy` (what targets may be fetched), over
an injectable `ResolvedTargetChecker` (`IpDenylist` +
`DenylistResolvedTargetChecker`) enforcing resolve-then-connect against the
private/metadata denylist for IPv4+IPv6.

**Rationale.** Redirect/chaining bypass, DNS rebinding and judge abuse are
distinct attack paths with distinct owners; splitting them keeps each rule
testable and prevents one policy's exception from opening another's hole.

Plan: §11.35 пп.13–14, §11.37 (redirect policy note). Invariant INV-003
adjacency (worker egress restrictions).

## Enforcement map

| Invariant | Enforcement |
|---|---|
| INV-003, INV-009, INV-012, INV-013 | Static content scans in `tests/Arch/InvariantsTest.php` |
| INV-011, INV-016, INV-018, INV-020 | Reflection/class-shape checks in the same file |
| INV-014, INV-015, INV-017 | Direct behavioral tests in the same file |
| INV-001, INV-002, INV-004, INV-005, INV-006, INV-007, INV-008, INV-010 | Explicit placeholders, enforced by later-stage tests |
