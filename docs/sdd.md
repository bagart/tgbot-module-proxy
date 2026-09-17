# Proxy Operations — Software Design Document

> **Module:** `bagart/tgbot-module-proxy` (`BAGArt\ProxyOperations`)
> **Version:** 0.1.0
> **Stack:** PHP 8.5, Laravel 13, Pest 4, React 19, Inertia 2, Tailwind 4
> **Date:** 2026-09-13
> **Status:** Post-MVP complete (Stages 0–11 + post-MVP: CW1, P1–P4, F1–F3; 1152 tests green)

---

## 1. Overview

Proxy Operations is a Telegram-integrated proxy management platform. It handles the full lifecycle: import → parse → inventory → audit → health scoring → pool selection → lease → export. All three user interfaces (Telegram Bot, Mini App, Web Admin) plus CLI and REST API operate over a single Application layer.

**Key properties:**
- Multi-tenant (1 user = 1 workspace, owner-only)
- 7 protocols: HTTP, HTTPS, SOCKS4, SOCKS4A, SOCKS5, SOCKS5H, MTProto
- Non-VPN scope guard (rejects vless/vmess/trojan/ss/wireguard/openvpn)
- Envelope encryption for credentials (KEK → DEK, AES-256-GCM)
- Append-only observations with shared raw probe cache
- Architectural invariants enforced by arch-tests (INV-001…INV-020)

---

## 2. Architecture

### 2.1 Layer Diagram

```
┌─────────────────────────────────────────────────────────────────┐
│  Interfaces                                                     │
│  Bot (Telegram) · Mini App (React 19) · Web Admin · CLI · API   │
├─────────────────────────────────────────────────────────────────┤
│  Application Layer                                              │
│  ApplicationServiceBus · Commands/Queries · Handlers            │
├──────────────────┬──────────────────────────────────────────────┤
│  Domain          │  Infrastructure                              │
│  Identity        │  Eloquent Models · Redis · Cache             │
│  Lifecycle       │  Transport Adapters (HTTP/SOCKS/DNS)         │
│  Failure/Taxonomy│  Encryption (KEK/DEK)                        │
│  Parsing         │  Wire DTOs (AuditTask/AuditResult V1)        │
│  Evidence        │  ProbeCache · Outbox · EventBus              │
│  Policy/SSRF     │                                              │
├──────────────────┴──────────────────────────────────────────────┤
│  Checker (separate worker container)                             │
│  ProbeExecutor → ProbeTool implementations → Network I/O        │
└─────────────────────────────────────────────────────────────────┘
```

### 2.2 Ownership Boundaries

| Layer | Owns | Does NOT own |
|-------|------|--------------|
| Domain | Identity model, lifecycle state machine, failure taxonomy, parsing grammar, policy/SSRF | Network I/O, persistence queries, encryption details |
| Application | Command/query routing, quota enforcement, audit logging | Domain transitions, health scoring, pool selection |
| Transport | HTTP CONNECT, SOCKS4/4a/5/5h adapters, DNS resolution, UDP ASSOCIATE | Lifecycle decisions, health scoring, credential decryption |
| Checker Worker | Probe execution, result normalization, sealed credential handling | Postgres writes, domain decisions, health/lifecycle |
| Infrastructure | Eloquent models, Redis queues, cache, encryption at rest | Business logic |

### 2.3 Module Registration

The module registers as a `TgModuleContract` plugin with `id='proxy'`, `capabilities=[Command, Ui]`, and `defaultEnabled=false`. The Laravel service provider (`ProxyOperationsServiceProvider`) wires ~60 singletons across 9 registration groups: transport, shared cache, checker, audit, audit delivery, leases, application layer, export, and console commands.

---

## 3. Domain Model

### 3.1 Identity Model (three levels)

```
EndpointIdentity   = scheme + host + port (canonicalized)
CredentialFingerprint = HMAC-SHA256(canonical credential payload)
AccessIdentity     = EndpointIdentity + CredentialFingerprint
```

`socks5://1.2.3.4:1080` and `socks5://user:pass@1.2.3.4:1080` are different `AccessIdentity` values. Dedup, probe-cache, leases, and verified projection all operate on `AccessIdentity`.

### 3.2 Core Entities

| Entity | Responsibility | Scope |
|--------|---------------|-------|
| `ProxyEndpoint` | Network identity (scheme/host/port, original host) | Tenant |
| `ProxyCredential` | Sealed credential envelope, fingerprint, masked repr | Tenant |
| `ProxyAccess` | Links endpoint + credential; carries lifecycle + health | Tenant |
| `ProxySource` | Import origin tracking | Tenant |
| `ProxyObservation` | Append-only raw probe evidence | Tenant |
| `ProxyHealth` | Derived health score, capability score, dimension signals | Tenant (access-level) |
| `ProxyCapability` | Derived protocol capabilities | Tenant |
| `ProxyPolicy` | Workspace quotas, politeness, retention, export rules | Tenant |
| `ProxyPool` | Static/Dynamic/Hybrid proxy groups | Tenant |
| `ProxyPoolMember` | Pool membership projection | Tenant |
| `ProxyLease` | Active lease per AccessIdentity, TTL-based | Tenant |
| `ProxyAuditJob` | Audit intent + status | Tenant |
| `ProxyAuditAttempt` | One execution attempt of a job | Tenant |
| `PolicySnapshot` | Immutable audit policy at job start | Tenant |
| `ProxyEvent` | Domain/integration/operational events + outbox | Tenant |
| `RawFeedEntry` | Staged import lines (parsed/error/skipped) | Tenant |
| `ProxyWorkspaceDek` | Per-workspace Data Encryption Key | Tenant |
| `VerifiedProxyProjection` | Audit-completed projection (read-only) | Tenant |
| `TelegramDcSetModel` | Telegram DC connectivity set | Global |

### 3.3 Lifecycle State Machine

```
AccessState: NEW → TESTING → WORKING → DEGRADED → FAILING → DEAD → RETIRED
```

Three orthogonal statuses on `ProxyAccess`:
- **AccessState** — the lifecycle ladder (hysteresis-gated)
- **TestabilityStatus** — TESTABLE / NOT_TESTABLE
- **QuarantineStatus** — NONE / QUARANTINED (with reason)

Transition rules are capability-aware and dimension-specific. Consecutive success/failure counts feed hysteresis.

### 3.4 Failure Taxonomy

`FailureClass` axis: `PROXY / TARGET / JUDGE / CHECKER / PLATFORM / POLICY`.

- `ProxyFailure` — written to `proxy_observations`, affects health
- `ExecutionFailure` — CHECKER/PLATFORM class, never reaches observations

18 failure codes (DNS_FAILURE, TCP_TIMEOUT, TCP_REFUSED, TLS_FAILURE, AUTH_FAILURE, PROXY_PROTOCOL_ERROR, TARGET_4XX, TARGET_5XX, BODY_STALL, JUDGE_UNAVAILABLE, JUDGE_INCONSISTENT, SSRF_BLOCKED, UNSUPPORTED_PROTOCOL, INVALID_CREDENTIAL, UDP_UNSUPPORTED, MTPROTO_HANDSHAKE_FAILED, RATE_LIMITED, TOOL_TIMEOUT).

### 3.5 Protocol & Capability Matrix

`ProxyProtocol` enum: HTTP, HTTPS, SOCKS4, SOCKS4A, SOCKS5, SOCKS5H, MTPROTO.

Full capability matrix covers: TCP connect, HTTP probe, Exit IP, Header leak, UDP ASSOCIATE, DNS remote, MTProto handshake, TG DC connectivity. Each transport adapter validates compatibility.

---

## 4. Module Structure

```
src/
├── ProxyOperationsModule.php          TgModuleContract entry
├── ProxyOperationsServiceProvider.php Laravel SP (~60 singletons)
├── Application/       (21 files)  Commands, queries, handlers, service bus
├── Audit/             (41 files)  Job/attempt/task lifecycle, health, events, cache, pools, leases
├── Auth/              (2 files)   MagicLinkService, TelegramInitDataVerifier
├── Benchmark/         (3 files)   BenchmarkRunner, SloReport, ProxyBenchmarkCommand
├── Bot/               (13 files)  Bot command router + 9 command handlers + WizardSession/Store/Router + Import/Export wizards
├── Checker/           (13 files)  ProbeExecutor, JudgeProvider, outcome classification
├── Console/           (12 files)  Artisan commands (import/list/check/export/pools/settings/status/backup/wal/benchmark/feed)
├── Decision/          (3 files)   DecisionLogService, DecisionQueryService
├── Domain/            (88 files)  11 subdirectories (see §3)
├── Encryption/        (4 files)   KEK/DEK envelope encryption
├── Export/            (19 files)  7 formatters (TXT/CSV/JSON/Proxychains/Curl/Clash/Telegram URI)
├── Feed/              (3 files)   FeedSyncService, FeedSyncContract
├── Http/              (16 files)  10 controllers, 3 middleware, 3 API resources
├── I18n/              (1 file)    ProxyTrans helper
├── Incident/          (4 files)   IncidentDetector, IncidentEscalator
├── Models/            (30 files)  29 Eloquent models + BelongsToTenant concern
├── Parser/            (1 file)    ImportProxiesService (orchestrator)
├── Support/           (1 file)    ProxyTrans helper
├── Tenancy/           (2 files)   TenantContext + exception
├── Tool/              (22 files)  ProbeTool contract, registry, manifests, 3 implementations
├── Transport/         (32 files)  Config, adapters (HTTP/SOCKS4/SOCKS5/DNS/UDP), resource governor
├── Web/               (4 files)   ChunkAsset, ProxyUi, ProxyInventoryHandler, ProxyWebApiHandler
└── Wire/              (7 files)   AuditTaskV1, AuditResultV1, SealedCredentialPayload, etc.
```

**~372 source files, ~128 test files (67 unit + 36 feature + 2 arch + 7 fixtures + 24 integration).**

---

## 5. Application Layer

### 5.1 Service Bus

`ApplicationServiceBus` routes commands and queries to handlers:

| Command/Query | Handler | Purpose |
|---------------|---------|---------|
| `ImportProxiesCommand` | `ImportProxiesHandler` | Import from paste/file/feed |
| `ExportInventoryCommand` | `ExportInventoryHandler` | Export in 7 formats |
| `StartAuditCommand` | `StartAuditHandler` | Trigger audit job |
| `AuditStatusQuery` | `AuditStatusHandler` | Check job status |
| `CancelAuditCommand` | `CancelAuditHandler` | Cancel running job |
| `WorkspaceSettingsQuery` | `WorkspaceSettingsHandler` | Read workspace quotas |
| `UpdateSettingsCommand` | `UpdateSettingsHandler` | Update workspace settings |

### 5.2 Application Commands

All implement `ApplicationCommand` (property hook for `tenantId`). DTOs are `final readonly` with constructor promotion.

### 5.3 Quota Enforcement

`QuotaEnforcer` checks per-workspace limits before each command: max proxies per import, concurrent audits, jobs per day.

---

## 6. Parser & Import

### 6.1 Grammar

`ProxyListParser` accepts one grammar covering:
- `host:port`
- `scheme://[user:pass@]host:port`
- `user:pass@host:port`
- `host:port:user:pass`
- `host port` (space-separated)
- CIDR expansion (with quota)
- `mtproto://secret` and `tg://proxy?...` URIs

### 6.2 Pipeline

```
Raw text → ProxyListParser → ParsedEntry[] → ImportProxiesService
  → EndpointCanonicalizer → identityHash dedup → ProxyEndpoint (save)
  → ProxyCredential (sealed via boot callback) → ProxyAccess → RawFeedEntry staging
```

### 6.3 Scope Guard

VPN protocols (vless, vmess, trojan, ss, wireguard, openvpn) are rejected with `UNSUPPORTED_PROTOCOL` parse error.

### 6.4 Encryption Boundary

Parser never encrypts. `ProxyCredential::booted()` seals the secret into `secret_envelope` via `CredentialEncryptor`. The domain DTO `ParsedEntry` carries plaintext `secret` — it never reaches serialization.

---

## 7. Transport & Checker

### 7.1 Transport Adapters

| Adapter | Protocols | Capabilities |
|---------|-----------|--------------|
| `HttpConnectAdapter` | HTTP, HTTPS | TCP CONNECT through HTTP proxy |
| `Socks4Adapter` | SOCKS4, SOCKS4A | SOCKS4 connect |
| `Socks5Adapter` | SOCKS5, SOCKS5H | SOCKS5 connect + auth |
| `Socks5UdpAdapter` | SOCKS5 (UDP) | UDP ASSOCIATE |
| `DirectAdapter` | (fallback) | Direct connection |

`TransportAdapterResolver` maps protocol → adapter. `ProxyConfigFactory` builds transport config from endpoint + credential.

### 7.2 DNS Resolution

Three modes: `LOCAL_DNS`, `REMOTE_DNS`, `PROXY_DNS`. `DnsLeakProbe` detects DNS leaks. `DnsResolverFactory` creates the appropriate resolver.

### 7.3 Probe Tools (Worker-Side)

| Tool | Implements | Purpose |
|------|-----------|---------|
| `HttpProbeTool` | `ProbeTool` | HTTP/HTTPS/SOCKS probe via curl-style execution |
| `TelegramDcProbeTool` | `ProbeTool` | TCP+TLS to Telegram DCs for connectivity check |
| `MtprotoProbeTool` | `ProbeTool` | Minimal MTProto handshake (req_pq_multi → resPQ) |

`ToolRegistry` allowlists tools. `ToolManifest` declares capabilities, limits, security policy.

### 7.4 Resource Governor

In-process governor enforces: max concurrent probes (50), max processes (100), max memory (512MB), max execution time (30s), max output bytes, max stdin bytes, max file descriptors.

### 7.5 Probe Execution Flow

```
CacheAwareProbePlanner → ProbeExecutor → ToolRegistry.resolve(toolId)
  → ProbeTool.execute(ProbeExecutionContext) → ProbeToolResult
  → ProbeOutcomeClassifier → ExecutionResultNormalizer → AuditResultV1
```

---

## 8. Audit Pipeline

### 8.1 Job Lifecycle

```
StartAuditCommand → JobStarter → AuditJob (CREATED)
  → AuditTaskFactory (seal credentials) → RedisStreamsAuditDeliveryQueue
  → DeliveryDispatcher → AuditAttempt → worker
  → AuditResultV1 → ResultIngestionService
  → ObservationWriter → HealthEvaluator → Lifecycle transitions → Events
```

### 8.2 AuditTrigger

MANUAL, SCHEDULED, IMPORT, FEED, LAZY_SELECTION, RECOVERY, TG_CHECK.

### 8.3 Policy Snapshot

`AuditPolicySnapshot` is immutable, built at job start by `PolicySnapshotBuilder`. Job runs against the snapshot, not the live policy.

### 8.4 Result Ingestion

`ResultIngestionService` performs: idempotency check → `ProbeDataEvidenceExtractor` → `ObservationWriter` (append-only) → `HealthEvaluator` (dimensional, hysteresis) → lifecycle state machine → `DbAuditEventRecorder` (transactional outbox).

### 8.5 Health Evaluation

`DimensionalHealthEvaluator`:
- Per-dimension evidence aggregation (TCP, TLS, HTTP, DNS, UDP, Telegram, bandwidth, judge)
- Hysteresis policy gates flapping
- Capability score vs health score (strictly separate)
- `formula_version` recorded for reproducibility

---

## 9. Shared Probe Cache

### 9.1 Key Structure

`ProbeCacheKeyV3` (10 fields): schema_version, endpoint_identity, credential_fingerprint, checker_node_id, egress_identity, judge_set_version, telegram_dc_set_version, probe_type, probe_profile_version, probe_semantics_version.

### 9.2 Value Contract

Cache values are safe raw evidence only (allowlisted fields). No tenant_id in key. No interpretation/scores in cache — those are per-tenant.

### 9.3 Implementation

`LaravelCacheProbeCache` backed by Laravel cache store. `CachePolicy` controls TTL per value kind. `CacheAwareProbePlanner` wraps `ProbeExecutor` to check cache before execution.

---

## 10. Pools, Selection & Lease

### 10.1 Pools

`PoolKind`: STATIC, DYNAMIC, HYBRID. `PoolPredicate` defines dynamic membership rules. `PoolMaterializer` rebuilds pool membership from current access state.

### 10.2 Selection

`ProxySelector` with 4 strategies: ROUND_ROBIN, LEAST_USED, RANDOM, WEIGHTED. Freshness gate checks `telegram_fresh_until`. Decision log records why candidates were skipped.

### 10.3 Lease

`LeaseService` manages: acquire (Redis SET NX PX atomic lock) → renew (heartbeat) → release → reclaim (reaper). One active lease per `AccessIdentity`. TTL default 300s. `LeaseReaperCommand` runs as cron.

---

## 11. Export

### 11.1 Formatters

| Format | Class | Purpose |
|--------|-------|---------|
| TXT | `TxtExportFormatter` | Simple text list |
| CSV | `CsvExportFormatter` | Comma-separated values |
| JSON | `JsonExportFormatter` | Structured JSON |
| Proxychains | `ProxychainsFormatter` | proxychains.conf format |
| Curl | `CurlFormatter` | curl -x snippets |
| Clash | `ClashFormatter` | Clash YAML config |
| Telegram URI | `TelegramProxyUriFormatter` | tg://proxy?... URIs |

### 11.2 Credential Safety

Export reads `masked_representation` from `proxy_credentials` by default. Explicit `includeCredentials` flag with confirmation required for plaintext export. Credentials are never logged.

### 11.3 Verified Projection

`VerifiedProxyProjector` updates `VerifiedProxyProjection` on `AuditCompleted` events. This is a read-only projection — `ProxyEndpoint` remains the single source of truth.

---

## 12. Interfaces

### 12.1 Telegram Bot

9 commands via `BotCommandRouter`: `/start`, `/help`, `/import`, `/list`, `/check`, `/stats`, `/get`, `/export`, `/settings`. `ProxyCommand` is the entry point (private chats only, tenant resolved via `TgBotOwner`).

### 12.2 Mini App (React 19)

5 pages: dashboard, inventory, pools, settings, jobs. Uses `@telegram-apps/sdk-react` with initData authentication. Shared component library with web admin.

### 12.3 Web Admin

Same React components as Mini App, behind magic-link authentication. `WebPanelEnabled` middleware gates access (disabled by default per workspace).

### 12.4 REST API

`/api/v1/` prefix: proxies (list/import/get/delete/export), audit (start/status), pools (list), settings (get/update). API resources for JSON responses.

### 12.5 CLI

12 Artisan commands: `proxy:import`, `proxy:list`, `proxy:check`, `proxy:export`, `proxy:pools:list`, `proxy:pools:create`, `proxy:settings`, `proxy:status`, `proxy:backup`, `proxy:wal:archive`, `proxy:benchmark`, `proxy:feed:sync`.

### 12.6 Auth

- **Mini App:** initData HMAC verification → session → CSRF
- **Web Admin:** Magic-link (one-time token, ≤15min lifetime, single-use, rate-limited)
- **Bot:** Tenant resolved from `TgBotOwner.bot_id → user_id`

---

## 13. Security

### 13.1 Credential Encryption

Envelope encryption: `PROXY_ENC_KEY` (env) → KEK → per-workspace DEK → credential fields. Format: `{key_version, algorithm, nonce, ciphertext, tag}`. Rotation via DEK rewrap.

### 13.2 SSRF Protection

Three independent policies:
- `ProxyEndpointConnectPolicy` — blocks private/metadata/link-local IPv4+IPv6
- `JudgeConnectPolicy` — fixed judge allowlist, anti-DNS-rebinding
- `TargetFetchPolicy` — body/connection limits

`IpDenylist` + `ResolvedTargetChecker` with resolve-then-connect.

### 13.3 Credential Delivery

Worker receives sealed `SealedCredentialPayload` (short-lived). Credentials travel via `CredentialChannel` (stdin/FD), never via argv. INV-013 enforced by arch-test.

### 13.4 Invariant Matrix

20 architectural invariants (INV-001…INV-020) enforced by `InvariantsTest.php` and `ParserInvariantsTest.php`:
- Health/lifecycle belong to ProxyAccess (INV-001/002)
- No persistence clients in Domain/Wire/Tool (INV-003)
- KEK/DEK ownership outside worker surfaces (INV-004)
- Shared cache contains no tenant interpretation (INV-005)
- tenant_id never from client as authoritative (INV-006)
- Parser never encrypts (INV-007)
- MTProto is not a transport (INV-008)
- No Redis client types in src (INV-009)
- Projection never mutates domain (INV-010)
- Domain entities hidden from tools (INV-011)
- No shell-out primitives in src (INV-012)
- Credentials only via CredentialChannel (INV-013)
- Checker-class codes rejected as observations (INV-014/015)
- Wire DTOs versioned with SCHEMA_VERSION (INV-016)
- toolSemanticsVersion in cache key (INV-017)
- ToolRegistry allowlist only (INV-018)
- Control-plane and execution-plane disjoint (INV-020)

---

## 14. Multi-Tenancy

**Model:** 1 user = 1 workspace. Tenant resolved before any domain logic.

**Defense-in-depth (3 levels):**
1. **Application:** Tenant-scoped repositories
2. **Domain:** Ownership checks at entity level
3. **Database:** BelongsToTenant global scope + composite FK

`tenant_id` in ALL domain tables. Never accepted from user input as authoritative. System dictionaries (judges, geo DB, blocklists) and shared probe cache are global.

---

## 15. Configuration

`config/proxy-operations.php` (269 lines, 10 top-level keys):

| Key | Contents |
|-----|----------|
| `tenancy` | Reserved |
| `encryption` | KEK env, AES-256-GCM, key_version, historical_keks |
| `quotas` | Reserved |
| `resource_governor` | Concurrency, memory, CPU, timeout limits |
| `retention` | Observation retention (disabled by default) |
| `audit` | Placement TTL, probe profiles, lifecycle thresholds, quarantine, health, events, cache, pools, leases, selection, delivery |
| `probe_defaults` | Reserved |
| `checker` | Timeouts, judge selection, max probes, judge budget |

---

## 16. Database Schema

26 migrations covering:

| Table | Type | Description |
|-------|------|-------------|
| `proxy_endpoints` | Source of truth | Network identity |
| `proxy_credentials` | Source of truth | Encrypted credential envelope |
| `proxy_accesses` | Source of truth | Endpoint + credential + lifecycle |
| `proxy_sources` | Source of truth | Import origin |
| `proxy_capabilities` | Derived | Protocol capabilities |
| `proxy_health` | Derived | Health/capability scores |
| `proxy_observations` | Append-only | Raw probe evidence |
| `proxy_policies` | Source of truth | Workspace quotas/settings |
| `proxy_workspace_deks` | Source of truth | Per-workspace DEKs |
| `raw_feed_entries` | Staging | Import line statuses |
| `policy_snapshots` | Immutable | Audit policy at job start |
| `proxy_audit_jobs` | Source of truth | Audit job status |
| `proxy_audit_attempts` | Source of truth | Execution attempts |
| `proxy_events` | Append-only | Domain events + outbox |
| `proxy_pools` | Source of truth | Pool definitions |
| `proxy_pool_members` | Projection | Pool membership |
| `proxy_pool_decisions` | Log | Selection decisions |
| `proxy_leases` | Source of truth | Active leases |
| `telegram_dc_sets` | Global | Telegram DC connectivity |
| `verified_proxies` | Projection | Audit-completed view |
| `proxy_exports` | Log | Export audit trail |
| `magic_link_tokens` | Auth | Web admin magic links |
| `proxy_feed_sources` | Source of truth | Feed subscription URLs |
| `proxy_decisions` | Log | Decision log entries |
| `proxy_incidents` | Source of truth | Incident detection/escalation |
| `proxy_gateway_tokens` | Auth | Gateway API keys |

---

## 17. Testing

### 17.1 Test Distribution

| Suite | Files | Description |
|-------|-------|-------------|
| Unit | 67 | Pure domain, no DB |
| Feature | 36 | Eloquent/Redis, SQLite in-memory |
| Arch | 2 | INV-001…INV-020 invariant enforcement |
| Fixtures | 7 | Test doubles |
| Integration | 24 | End-to-end scenarios |

### 17.2 Verification

```bash
# From module directory
composer test
# or from root
vendor/bin/pest --testsuite ProxyModule
```

### 17.3 Architecture Tests

`InvariantsTest.php` — 20 tests enforcing architectural invariants via static source scanning and reflection. `ParserInvariantsTest.php` — 6 tests enforcing parser-specific invariants (no encryption, no tenancy, MTProto not transport).

---

## 18. I18n

5 languages: EN, RU, FR, ES, ZH. Each has:
- `lang/{locale}.json` — 36 translation keys (import/export/audit/pool/settings/errors/bot/UI)
- `lang/{locale}/settings.php` — 59 keys for admin panel field labels

Domain returns error codes only; locale applied in presentation layer via `ProxyTrans` helper.

---

## 19. Deployment

### 19.1 Dev Mode

Root `composer.json` has path repository `{ "type": "path", "url": "misc/BAGArt/tgbot-module-proxy" }`. PSR-4 autoload maps `BAGArt\ProxyOperations\` → `src/`. Edits are immediately visible.

### 19.2 Prod Mode

`composer.prod.json` with versioned constraints, no path repositories. Installed via `cmd/deps/install --mode=prod`.

### 19.3 Module Engine

Registered in `config/tg_modules.php` with:
- `laravelProvider: ProxyOperationsServiceProvider::class`
- `provider: ProxyOperationsModule::class`
- Commands: `RunCapabilityProbesCommand`, `LeaseReaperCommand`
- Schedule: `proxy:lease:reap` every minute
- Frontend pages: `resources/js/pages`
- Settings screen with 7 configurable fields

---

## 20. Scope Boundary

Completed post-MVP decisions are retained in Appendix C rather than duplicated as roadmap tasks. Deferred scope is tracked in the host `dev-ai.md`: ExternalBinaryExecutor (W1c-EXEC), multi-region fleet (R10), and standalone parser service (R13). None is included in the shipped scope or assigned a delivery date.

---

## Appendix A: ADR-001 Summary

Stage 0 contract decisions (accepted 2026-08-26), covering identity model, failure taxonomy, lifecycle, wire contracts (AuditTaskV1/AuditResultV1), tool boundary (ProbeTool interface + ToolRegistry), cache key V3, idempotency split, snapshots (JudgeSet/TelegramDc/AuditPolicy), and SSRF policy split (3 independent policies). Full text: `adr/ADR-001-stage0-contracts.md`.

## Appendix C: Post-MVP Completed Features

### CW1: Bot Wizard Flows

Multi-step wizard flows in Telegram Bot for the full proxy vertical. Implemented via `WizardRouter` + `WizardSession` (Redis-backed, TTL 30min) + `ImportWizard` / `ExportWizard`. Uses inline keyboards with callback_data routing. Registered in `ProxyOperationsServiceProvider`.

### P1: Backup / PITR

`proxy:backup` command (pg_dump baseline, compressed), `proxy:wal:archive` for WAL archiving, observation table partitioning by month. `ObservationRetentionPolicy` with partitioning flag.

### P2: SLO Benchmarking

`proxy:benchmark` command with configurable parameters. `BenchmarkRunner` executes concurrency × protocol matrix. `SloReport` DTO generates markdown/JSON reports.

### P3: Feed Sync Engine

`FeedSyncService` (implements `FeedSyncContract`) fetches URLs, reuses `ProxyListParser`, applies VPN scope guard, dedup via `AccessIdentity`, stages as `RawFeedEntry`, executes `ImportProxiesCommand`. `proxy:feed:sync` command for cron-based sync. `ProxyFeedSource` model for subscription management.

### P4: Alerting & Health Endpoints

Health endpoints: `GET /health/live` (liveness), `GET /health/ready` (readiness: DB + Redis), `GET /health/detailed` (worker status, queue depth, connection stats). JSON-based monitoring (no Prometheus exposition format).

### F1: Incident Engine

`IncidentDetector` (evaluates metrics, detects anomalies: high failure rate, pool exhaustion, worker down, judge unavailable). `IncidentEscalator` with escalation levels L1–L3. `Incident` model (table: `proxy_incidents`). `IncidentController` for API access. Debounce: same incident type within 1 hour suppressed.

### F2: Decision Log UI

`DecisionLogService` + `DecisionQueryService` for querying `proxy_decisions` and `proxy_pool_decisions`. `DecisionController` for API access. Supports timeline view, filtering by proxy/pool/type/date.

### F3: Gateway API

`GatewayToken` model (API key auth), `GatewayAuthMiddleware` (X-API-Key + HMAC signature). `GatewayController` with assign/release endpoints. Uses `ProxySelector` strategies + `ProxyLease` TTL-based acquisition. Credential delivery via `CredentialChannel` (stdin/FD, never argv).

### W1: Worker Pipeline (14/15 items done)

Worker execution pipeline fully operational. `TransportCapabilityDaemon` consumes tasks from Redis Streams, dispatches via `WorkerExecutionPlaneHandler` → `ProbeExecutor` → `ProbeOutcomeClassifier` → `ExecutionResultNormalizer`. Results ingested via `ResultIngestionService` (idempotency → evidence → observations → health → lifecycle → outbox). Credential sealing via `AuditTaskFactory` + `CredentialSealer`, delivery via `CredentialChannel` (stdin/FD). `CacheAwareProbePlanner` checks shared cache before execution. All 7 `AuditTrigger` types handled. `formula_version` recorded. ToolRegistry allowlist enforced.

**Deferred:** `ExternalBinaryExecutor` — centralized `proc_open` wrapper not needed; all probe tools are PHP-native.

## Appendix B: File Statistics

| Category | Count |
|----------|-------|
| Source files (src/) | ~372 |
| Test files (tests/) | ~128 |
| Migrations | 31 |
| Lang files | 10 |
| Config lines | 269 |
| Total assertions | 3820 |
