# Proxy Operations — Execution Task Files

Sub-plan files for subagent execution. One file = one reasonably-sized task.
Source of truth for content: `../plan.md` (sections referenced in each file).
Status tracking lives here; agents must update the Status table when done.

## Global rules (apply to every task file)

- PHP ^8.5, `declare(strict_types=1)`, English code/comments, LF only, no git operations (no add/commit/push).
- DTO style: `final readonly` classes, constructor-promoted typed properties, no setters;
  anything serialized implements `JsonSerializable` + `public const SCHEMA_VERSION` +
  `static fromJson(array): static` with version match → private `fromJsonV1()`.
  Reference implementation: `telegram-bot-lib/src/Outbound/DeadLetterEntry.php`.
- Enums: TitleCase keys, backed enums. Strict contracts only — no `method_exists`,
  no duck typing, no dead public methods (every public method needs a real consumer/design source).
- Secrets: never logged, never in exceptions/messages; masked by default.
- Namespace: `BAGArt\ProxyOperations\...` → `src/`; tests `BAGArt\ProxyOperations\Tests\...` → `tests/`.
- Tests: Pest, under `tests/Unit/` (pure) and `tests/Feature/` (DB/Eloquent).
  Verify from module dir: `timeout 120 ../../../vendor/bin/pest` (or
  `composer test`, which delegates to host `vendor/bin/pest` — no local vendor
  install needed). DB-backed Feature tests rely on the host app being booted by
  `pestphp/pest-plugin-laravel` with `DB_CONNECTION=sqlite` / `DB_DATABASE=:memory:`
  from the module `phpunit.xml.dist` — same mechanism as
  `telegram-bot-antispam-module` (see T01 for the exact harness setup).
- Every task adds/updates tests; a task is done only when `composer test` passes
  and new code is covered.

## Dependency graph

```
Stage 0: T00 → T87 → T88 → T89 → T90 → T91 → T92 → T93 → T94   (all done)
                                └─────────┐└──────┐
                                   T93 (∥ after T88)   T92 (∥ after T90 possible)

Stage 1: T01 → T02 → T03 → T04 → T05 → T06
                │      └─── T04 → T09
                └→ T08            T07 (∥ after T05)

Stage 2: T03 → T10 → T11 → T12 → T13
          T04 ↗     ↗     T09 ↗
          T02 ↗

Stage 3: T90,T91 → T15 → T16 → T17 → T18

Stage 4: T93,T18,T90,T91 → T19 → T20 → T21 → T22
```

Default execution: linear. Safe parallel pairs noted per file.
Recommended Stage 1 order: T01 → T02 → T03 → T04 → T05 → {T06 ∥ T07 ∥ T08} → T09.
Recommended Stage 2 order: T10 (grammar, after T03+T04) → T11 (RawFeedEntry, after T02) → T12 (import service, after T09+T10+T11) → T13 (integration tests, after T12).

## Stage 0 task files

| File | Plan ref | Scope | Depends on | Status |
|---|---|---|---|---|
| `T00-module-skeleton.md` | §7 | composer/phpunit/tests scaffolding | — | done |
| `T87-identity-model.md` | #87, §§11.2–11.5, 11.35 | EndpointIdentity canonicalization, CredentialFingerprint, AccessIdentity, protocol/capability matrix | T00 | done |
| `T88-failure-taxonomy.md` | #88, §§11.16–11.17, 11.39 пп.12–14 | FailureCode/FailureClass, descriptors, ExecutionFailure vs ProxyFailure, ProbeProfile | T87 | done |
| `T89-lifecycle-evidence.md` | #89, §11.6, §11.35 пп.9–11 | AccessState machine, dimension evidence + applicability, VerifiedEligibilityPolicy contract | T88 | done |
| `T90-wire-contracts.md` | #90, §§11.9–11.10, 11.39 пп.5–6,18 | AuditTask/AuditResult/ProbeExecution V1, sealed credential delivery | T89 | done |
| `T91-tool-boundary.md` | #91, §11.39 | ProbeTool contract, ToolRegistry/Manifest, governor spec, transports, planes | T90 | done |
| `T92-cache-idempotency-events.md` | #92, §§11.7, 11.19–11.20, 11.39 п.12 | ProbeCacheKeyV3, shared cache value allowlist, idempotency split, EventEnvelope | T91 | done |
| `T93-snapshots-ssrf-policies.md` | #93, §11.35 пп.7–8,13–14 | JudgeSet/DcSet/AuditPolicy snapshots, SSRF policy trio | T88 | done |
| `T94-invariants-adr.md` | #94, §11.37, §11.39 | INV-001…020 arch-tests, ADR-001 | all above | done |

## Stage 1 task files (core domain, plan tasks #1–15)

Refined from `stage-01-core-domain.md`. Scope: Eloquent models + migrations +
factories for the inventory core; every model tenant-scoped via T02
(INV-006); negative tenant-scoping tests mandatory in every task.

| File | Plan rows | Scope | Depends on | Status |
|---|---|---|---|---|
| `T01-module-laravel-skeleton.md` | #1 infra, #23 | service provider, `config/proxy-operations.php`, DB test harness (host pest + sqlite :memory:), host wiring steps | — | done |
| `T02-tenancy-foundation.md` | #23, INV-006 | TenantContext + BelongsToTenant trait/global scope, no-context failures | T01 | done |
| `T03-proxy-endpoint.md` | #1, #3 | `proxy_endpoints` migration+model+factory, canonical identity mapping | T02 | done |
| `T04-proxy-credential.md` | #3, #5 | `CredentialKind` enum, `proxy_credentials` migration+model+factory, fingerprints, masking | T03 | done |
| `T05-proxy-access.md` | #3, #5, INV-001/002 | `proxy_accesses` migration+model+factory, lifecycle enums from Domain\Lifecycle, telegram freshness fields | T03, T04 | done |
| `T06-source-capability-health.md` | #3 | `proxy_sources`, derived `proxy_capabilities`, access-scoped `proxy_health` (+formula versions R6.6) | T05 | done |
| `T07-proxy-observations.md` | #24 | append-only `proxy_observations`, immutability guard, safe-evidence JSON | T05 | done |
| `T08-proxy-policy-quotas.md` | #8 (data part) | `proxy_policies`: quotas/politeness/retention/export-rules/ui flags per workspace | T02 | done |
| `T09-credential-envelope-encryption.md` | #7 | DEK table, CredentialEncryptor/EncryptedField/KekProvider, rewrap rotation, INV-004 arch-test | T04, T01 | done |

## Stage 2 task files (parser, plan tasks #16–20 / #86)

Refined from `stage-02-parser.md`. Scope: grammar library (`Domain\Parsing`),
staging model (`RawFeedEntry`), application service (`ImportProxiesCommand`),
and comprehensive integration tests. Parser never encrypts (INV-007); credential
sealing happens in the `ProxyCredential::booted()` callback (T09 pattern).
All Eloquent operations run within TenantContext (INV-006).

| File | Plan ref | Scope | Depends on | Status |
|---|---|---|---|---|
| `T10-parser-grammar.md` | #86, §§10.12 п.3, 11.15 п.2–3, 11.28 | `Domain\Parsing` grammar library: ProxyListParser, ParsedEntry, ParseError, ParseResult DTOs; VPN rejection guard | T03, T04 | done |
| `T11-raw-feed-entry.md` | §§11.21, 11.28, 11.35 п.16–17, R6.8 | `raw_feed_entries` migration+model+factory, RawFeedEntryStatus enum, import staging + idempotency | T01, T02 | done |
| `T12-import-command.md` | §§10.12 п.23, 11.10, 11.15 п.2–3, 11.28 | `ImportProxiesCommand` DTO, `ImportProxiesService` application service: parser→Eloquent bridge, dedup, credential sealing, CIDR expansion | T02, T03, T04, T09, T10, T11 | done |
| `T13-parser-tests.md` | §§10.12 п.3/22–23, 11.15, 11.28, INV-006/007/008 | Integration tests: multi-format import, tenant isolation, idempotency, MTProto, arch invariant guards | T10, T11, T12 | done |

### Stage 2 dependency graph

```
T03 ──→ T10 ──→ T12 ──→ T13
T04 ──↗      ╲        ╱
T02 ──→ T11 ──→ T12
T09 ──────────→ T12
```

- T10 (grammar) needs T03/T04 for `ProxyProtocol`/`CredentialKind` enums.
- T11 (RawFeedEntry) needs T01/T02 for migration harness + tenancy.
- T12 (import service) needs all of T03, T04, T09, T10, T11.
- T13 (tests) needs all of T10, T11, T12.

Plan-row dispositions for §7 Phase 0 not covered above:
rows #4 (failure taxonomy) and #6 (SSRF policies) are already done in Stage 0
(T88/T93); row #2 (non-VPN guard) is Stage 2 (parser); rows #9–#15
(judge/geo/reputation/rotate/udp/stall/latency engines) belong to checker
stages 3–5 per plan §11.14 and are NOT part of Stage 1 persistence work —
their storage surfaces (percentiles, udp/dns capability columns) are already
reserved in T06/T07 schemas.

### Open decisions flagged during refinement

- **OD-1 — no `workspaces` table.** Plan §11.21 marks `workspaces` as
  platform-owned (= users, 1:1). Stage 1 uses `tenant_id → users.id` directly;
  the reserved `role` column has nowhere to live until a workspace table is
  actually introduced. Owner sign-off assumed.
- **OD-2 — observations partitioning.** Plan §§6/[DB], 11.21 require
  time-partitioning of `proxy_observations` "from day one", but Postgres
  declarative partitions are not expressible in portable migrations and break
  the sqlite-based module test harness. T07 ships a plain table with the final
  index layout; a pg-native partition DDL migration is deferred to the deploy
  stage and must be tracked.
- **OD-3 — encryption defaults.** KEK source/format is decided (§10.12 п.13);
  T09 assumes AES-256-GCM, DEK storage in `proxy_workspace_deks`, config-held
  `key_version=k1`. Override before executing T09 if undesired.
- **OD-4 — host-tree touches.** T01 requires one line in host
  `bootstrap/providers.php`, PSR-4 factory mappings in host `composer.json`
  (+ `composer dump-autoload`) — outside the module tree, same as sibling modules.

## Stage 3 task files (ASK transport, plan tasks #76/#81)

Refined from `stage-03-transport.md`. Scope: proxy-aware transport over
`bagart/php-async-kernel-client` — HTTP CONNECT, SOCKS4/4a/5/5h; capability
probes; UDP ASSOCIATE and DNS modes; resource governance; ASK daemon wiring;
worker control/execution plane routes. All transport adapters are pure PHP
(network I/O via ASK Fiber-based runtime); credentials delivered via
`CredentialChannel` (stdin/FD), never argv (INV-013).

| File | Plan ref | Scope | Depends on | Status |
|---|---|---|---|---|
| `T15-proxy-config.md` | #76, §§11.4–11.5, 11.39 пп.3,5–6,8 | ProxyConfig/ProxyCredentialRef/TlsOptions DTOs, TransportOptions hierarchy (SocksOptions, HttpConnectOptions, MtprotoOptions), ProxyConfigValidator, ProxyConfigFactory, ProbeContextBuilder | T90, T91 | done |
| `T16-transport-adapters.md` | #76, §11.39 пп.3,5–6,8 | TransportAdapterContract, HttpConnectAdapter, Socks4Adapter, Socks5Adapter, DirectAdapter, TransportAdapterResolver, CredentialPayload/Unsealer/Decryptor | T15, T91 | done |
| `T17-udp-dns-modes.md` | §§11.5, 11.39 пп.3,5 | Socks5UdpAdapter (UDP ASSOCIATE), UdpAssociateResult/UdpRelayHandle/UdpDatagramResult DTOs, DnsResolverContract, LocalDnsResolver, RemoteDnsResolver, ProxyDnsResolver, DnsResolverFactory, DnsLeakProbe | T16, T15 | done |
| `T18-worker-wiring.md` | §§11.30, 11.39 пп.4,10,15–16, #81 | TransportToolManifestProvider, CapabilityProbeRunner/CapabilityProbeResult, ResourceGovernor, TransportCapabilityDaemon (ASK), WorkerControlPlaneHandler, WorkerExecutionPlaneHandler, RunCapabilityProbesCommand, service provider wiring | T15, T16, T17, T90, T91 | done |

### Stage 3 dependency graph

```
T90 ──────────────────────────────────────→ T15 ──→ T16 ──→ T17
T91 ──────────────────────────────────────→ T15 ──→ T16 ──→ T17
                                                      │
                                              T15 ──→ T18
                                              T16 ──→ T18
                                              T17 ──→ T18
                                              T90 ──→ T18
                                              T91 ──→ T18
```

- T15 (ProxyConfig DTOs) needs T90 (wire contracts) and T91 (Tool contracts).
- T16 (Transport Adapters) needs T15 (ProxyConfig as input).
- T17 (UDP/DNS) needs T16 (Socks5Adapter) and T15 (SocksOptions).
- T18 (Worker Wiring) needs T15–T17 (all transport code) + T90/T91 (contracts).

Recommended execution: T15 → T16 → T17 → T18.

## Stage 4 task files (checker engine, plan tasks #25–30)

Refined from `stage-04-checker-engine.md`. Scope: ProbeExecutor orchestration,
JudgeProvider (judge resolution from snapshots), ExecutionResultNormalizer
(ExecutionFailure/ProxyFailure separation boundary), service provider wiring.
Worker = orchestration only; no domain decisions, no Postgres writes
(INV-003/009/014/015).

| File | Plan ref | Scope | Depends on | Status |
|---|---|---|---|---|
| `T19-judge-provider.md` | §§11.8, 11.17, 11.35 п.8 | JudgeProvider contract, JudgeSetProvider (round-robin/all/random selection), JudgeBudgetTracker (rate-limit sliding window) | T93 | done |
| `T20-probe-executor.md` | §§11.30, 11.39 пп.5–6,8,15 | ProbeExecutor orchestrator: AuditTaskV1 → probe planning → tool dispatch → result collection; ProbeExecutionOutcome/ProbeSingleResult DTOs; ToolTimeoutFactory | T18, T19, T90, T91 | done |
| `T21-execution-result-normalizer.md` | §§11.9, 11.16, 11.39 пп.13–14 | ExecutionResultNormalizer: raw ProbeToolResult → AuditResultV1 with strict ExecutionFailure/ProxyFailure separation (INV-014/015); ProbeOutcomeClassifier | T20, T90, T88 | done |
| `T22-checker-wiring.md` | §§11.14, 11.30, 11.39 п.19 | Service provider bindings, config/checker section, integration tests for full pipeline | T19, T20, T21, T01 | done |

### Stage 4 dependency graph

```
T93 ──────────────────────────────────→ T19 ──→ T20 ──→ T21
T90 ──────────────────────────────────→ T20 ──→ T21
T91 ──────────────────────────────────→ T20
T18 ──────────────────────────────────→ T20
T88 ──────────────────────────────────→ T21
                                              │
T19 ──→ T20 ──→ T21 ──→ T22
T01 ───────────────────────────────────────→ T22
```

- T19 (JudgeProvider) needs T93 (JudgeSetSnapshot DTOs).
- T20 (ProbeExecutor) needs T18 (ResourceGovernor + transport wiring),
  T19 (JudgeProvider), T90 (AuditTaskV1), T91 (ProbeTool/ToolRegistry).
- T21 (ExecutionResultNormalizer) needs T20 (ProbeExecutionOutcome),
  T90 (AuditResultV1), T88 (FailureCode/FailureTaxonomy).
- T22 (Wiring) needs all of T19–T21 + T01 (service provider pattern).

Recommended execution: T19 → T20 → T21 → T22.

## Stage 5 task files (audit pipeline, plan tasks #31–40)

Refined from `stage-05-audit-pipeline.md`. Scope: AuditJob (+trigger,
+immutable AuditPolicySnapshot) → Attempt → worker delivery over Redis
Streams (idempotency from T92) → AuditResult ingestion → Observations →
Health evaluation (hysteresis) → Lifecycle transitions → Events
(EventEnvelope + transactional outbox ordering). Worker never touches
Postgres; all domain writes go through the application layer
(§§11.9, 11.18–11.20, 11.35 пп.4–7,18–19). Resolves OD-5 (additive
`probeData` on `AuditResultV1`, T26).

| File | Plan ref | Scope | Depends on | Status |
|---|---|---|---|---|
| `T23-audit-job-model.md` | §§11.18, 11.21, 11.35 п.7 | `proxy_audit_jobs`/`_attempts`/`policy_snapshots` migrations + models + factories; AuditTrigger/status enums; immutable snapshot rows | T02, T05, T08, T93 | done |
| `T24-job-starter.md` | §§11.18, 11.27, 11.35 п.7 | AuditRequest DTO, PolicySnapshotBuilder (content-hash reuse + policy_version), JobStarter with TTL placement idempotency | T23, T08, T92, T02 | done |
| `T25-task-delivery.md` | §§11.9, 11.19, 11.30, 11.35 пп.5–6 | AuditTaskFactory (sealed credential, R6.5), AuditDeliveryQueue over Redis Streams, DeliveryDispatcher (Job→Attempt→Task), retry budget + DLQ | T23, T24, T90, T09, T16, T92, T18 | done |
| `T26-result-ingestion.md` | §§11.7, 11.9, 11.35 пп.1,9,18 | OD-5 resolution (additive `probeData` on AuditResultV1), ResultIngestionService (idempotency + transactional unit of work), ProbeDataEvidenceExtractor, ObservationWriter, HealthEvaluator/AuditEventRecorder contracts | T25, T90, T07, T89, T23 | done |
| `T27-health-lifecycle.md` | §§11.6, 11.7, 11.35 пп.1,9–11 | DimensionalHealthEvaluator: per-dimension evidence → hysteresis → capability-aware AccessStateMachine transitions; ProxyHealth persistence (R6.6 formula version); telegram freshness | T26, T89, T06, T05 | done |
| `T28-events-outbox.md` | §§11.9, 11.20, 11.35 пп.18–19 | `proxy_events` (event log + outbox, R6.7 immutability semantics), DbAuditEventRecorder, EventOutboxDispatcher (SKIP LOCKED, at-least-once), end-to-end pipeline tests with failure injection | T26, T27, T92, T02 | done |

### Stage 5 dependency graph

```
T02,T05,T08,T93 ──→ T23 ──→ T24 ──→ T25 ──→ T26 ──→ T27 ──→ T28
T08,T92 ──────────────────↗      T90,T09,T16,T18 ↗  T89,T06,T05 ↗
T07,T89,T25,T23 ────────────────────────→ T26
T92,T02 ────────────────────────────────────────→ T28
```

- T23 (persistence) is the foundation; T24 (job starter) needs it.
- T25 (delivery) needs T24 + wire contracts + credential sealing.
- T26 (ingestion) consumes results from T25; introduces the
  HealthEvaluator/AuditEventRecorder contracts.
- T27 (health/lifecycle) implements HealthEvaluator.
- T28 (events/outbox) implements AuditEventRecorder and closes the stage
  with end-to-end tests.

Recommended execution: T23 → T24 → T25 → T26 → T27 → T28.

### Open decisions flagged during refinement

- **OD-5 — Successful probe data transport.** `AuditResultV1.observations` is
  `list<ProxyFailure>` — it carries proxy-side failures only. Successful probe
  data (latency, headers, exit_ip) needs a transport mechanism to the evidence
  pipeline. Options: (a) extend `AuditResultV1` with a `probeData` field (additive
  schema change), (b) carry success data in the `context` of a zero-observation
  `ProxyFailure` (hack), (c) pass raw `ProbeToolResult.observations` alongside
  `AuditResultV1` in the application layer. **RESOLVED in T26:** option (a) —
  additive optional `probeData` field, contents restricted to the shared-cache
  value allowlist (§11.35 п.2), backward compatible (absent → `[]`).
- **OD-6 — Judge rate-limit scope.** `JudgeBudgetTracker` is in-memory per
  worker process. If multiple workers share a judge set, rate limits are
  per-process, not global. Acceptable for MVP; global budget requires Redis
  counter (deferred to production hardening).

## Stage 6 task files (raw probe cache, plan tasks #41–44)

Refined from `stage-06-cache.md`. Scope: ProbeCacheKeyV3-backed shared cache
(raw evidence allowlist only, INV-005), CachePolicy (TTL per value kind,
negative caching), cache-aware probe planner in the worker, hit/miss metrics,
shared cache ON (§11.14).

| File | Plan refs | Covers | Depends on | Status |
|---|---|---|---|---|
| `T29-cache-policy-infra.md` | §§11.7, 11.14, R6.2/R6.4 | CachePolicy DTO + `audit.cache` config, `NegativeProbeResult` kind, ProbeCacheEntry, ProbeCache contract, LaravelCacheProbeCache | T92 | done |
| `T30-cache-aware-planner.md` | §§11.7, 11.14, 11.39 пп.5–6 | ProbeCacheKeyFactory, ProbeCacheMetrics, CacheAwareProbePlanner (hit/negative-hit/miss semantics) | T29, T20, T22 | done |
| `T31-cache-wiring-e2e.md` | §11.14, §11.35 пп.5–6 | container wiring, checker-path integration, cross-tenant E2E + failure injection, metrics assertions | T29, T30 | done |

### Stage 6 dependency graph

```
T92 ──→ T29 ──→ T30 ──→ T31
T20,T22 ────────↗
```

### Deviation pre-declared during refinement

- `ProbeCacheKeyV3` has no explicit `probeType` field (V3 folds probe identity
  into profile/semantics versions). Key factory composes
  `probeSemanticsVersion = "<probeType>@<base>"` instead of changing the frozen
  V3 schema.

Recommended execution: T29 → T30 → T31.

## Stage 7 task files (pools, selection, lease, plan tasks #45–52)

Refined from `stage-07-pools-selection-lease.md`. Scope: pool = projection
of healthy accesses (STATIC/DYNAMIC/HYBRID + predicate), dynamic
materializer + decision log, ProxySelector with explainable selection,
ProxyLease with Redis lock + crash recovery (reaper). All tenant-scoped.

| File | Plan refs | Covers | Depends on | Status |
|---|---|---|---|---|
| `T32-pool-model.md` | §§11.21, 11.25, 11.22 | proxy_pools/_members migrations, PoolKind/PoolPredicate/PoolCandidateView, PoolRepository, tenancy tests | T02, T05, T07 | done |
| `T33-pool-materializer.md` | §§11.25, 11.20 | PoolMaterializer (projection, reproducible, HYBRID preservation), proxy_pool_decisions (decision log), `PoolRebuilt` event | T32, T27, T28 | done |
| `T34-lease-service.md` | §11.24, §10.12 пп.6, §11.9 | proxy_leases (one ACTIVE per AccessIdentity), LeaseLockStore (Redis), LeaseService acquire/renew/release/reclaim, lease events | T02, T05, T16, T28 | done |
| `T35-selector-reaper.md` | §11.25, §11.24, §10.12 п.9 | SelectionCriteria, SelectionStrategy (RR/random/least-used/weighted), ProxySelector (freshness gate + lazy-check job), decision-log entries, LeaseReaperTick + command | T32–T34, T24, T27 | done |

### Stage 7 dependency graph

```
T02,T05,T07 ──→ T32 ──→ T33 ──→ T35 ←── T24,T27
T05,T16,T28 ──→ T34 ──────────────↗
```

### Deviations pre-declared during refinement

- Selector API is synchronous `?ProxyLeaseDto` for this MVP; the plan's
  `Promise<?ProxyLease>` (Fiber await, §10.12 п.9) belongs to the Gateway
  phase — async wrapper intentionally deferred.
- Diversity (geo/IP anti-correlation, plan §5 Phase B/C) is out of scope;
  single-criteria strategies only.
- Decision-log entries reuse the `proxy_pool_decisions` table from T33
  (selection runs write `materialization_id` = selection run id).

Recommended execution: T32 → T33 → T34 → T35.

## Later-stage sub-plans (coarse-grained, refined before execution)

| File | Covers plan tasks | Status |
|---|---|---|
| `stage-01-core-domain.md` | #1–15 | refined → T01–T09 |
| `stage-02-parser.md` | #16–20, #86 | refined → T10–T13 |
| `stage-03-transport.md` | #76, #81 | refined → T15–T18 |
| `stage-04-checker-engine.md` | #25–30 | refined → T19–T22 |
| `stage-05-audit-pipeline.md` | #31–40 | refined → T23–T28 |
| `stage-06-cache.md` | #41–44 | refined → T29–T31 |
| `stage-07-pools-selection-lease.md` | #45–52 | refined → T32–T35 |
| `stage-08-telegram-compat.md` | #53–56 | coarse |
| `stage-09-projections-export.md` | #57–61 | coarse |
| `stage-10-api-application-layer.md` | #62–70 | coarse |
| `stage-11-vertical-mvp.md` | #71–79 | coarse |
