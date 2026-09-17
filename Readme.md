# tgbot-module-proxy

Proxy Operations module for the Telegram bot platform: proxy inventory,
auditing, health/lifecycle, pools, lease and verified export.

## Documentation

- **SDD**: `docs/sdd.md` (Software Design Document — full architecture)
- **ADR**: `docs/adr/ADR-001-stage0-contracts.md` (Stage 0 contract decisions)
- **Tasks**: `docs/tasks/` (active plans, ephemeral)

## Features

### Core (Stages 0–11)

- **Import**: multi-format parser (HTTP/SOCKS4/SOCKS4A/SOCKS5/SOCKS5H/MTProto), VPN scope guard, CIDR expansion, dedup via `AccessIdentity`
- **Audit**: probe execution pipeline, dimension-based health evaluation, lifecycle state machine (`NEW → TESTING → WORKING → DEGRADED → FAILING → DEAD → RETIRED`)
- **Pools**: static/dynamic/hybrid, predicate-based membership, materialization, selection strategies (ROUND_ROBIN/LEAST_USED/RANDOM/WEIGHTED)
- **Lease**: Redis SET NX PX atomic lock, TTL-based, reaper cron
- **Export**: 7 formatters (TXT/CSV/JSON/Proxychains/Curl/Clash/Telegram URI), credential masking
- **Shared Probe Cache**: `ProbeCacheKeyV3`, tenant-interpretation-free, `CacheAwareProbePlanner`
- **Security**: envelope encryption (KEK→DEK, AES-256-GCM), SSRF protection (3 policies), credential delivery via `CredentialChannel` (stdin/FD)

### Post-MVP

- **Bot Wizard Flows** (CW1): `WizardRouter` + `WizardSession` (Redis-backed) + `ImportWizard` / `ExportWizard`
- **Feed Sync** (P3): `FeedSyncService`, `proxy:feed:sync` cron, `ProxyFeedSource` model
- **Backup / PITR** (P1): `proxy:backup`, `proxy:wal:archive`, observation partitioning
- **SLO Benchmarking** (P2): `proxy:benchmark`, `BenchmarkRunner`, `SloReport`
- **Health Endpoints** (P4): `/health/live`, `/health/ready`, `/health/detailed`
- **Incident Engine** (F1): `IncidentDetector`, `IncidentEscalator`, auto-resolve
- **Decision Log UI** (F2): `DecisionLogService`, `DecisionController`
- **Gateway API** (F3): `GatewayToken` auth, assign/release endpoints, `ProxySelector` strategies

## Interfaces

- **Telegram Bot**: 9 commands via `BotCommandRouter` (private chats only, tenant = bot owner)
- **Mini App**: React 19 + @telegram-apps/sdk-react (5 pages: dashboard, inventory, pools, settings, jobs)
- **Web Admin**: Same React components, magic-link auth
- **REST API**: `/api/v1/` prefix (proxies, audit, pools, settings, gateway, health)
- **CLI**: 12 Artisan commands (`proxy:import`, `proxy:list`, `proxy:check`, `proxy:export`, `proxy:pools:list`, `proxy:pools:create`, `proxy:settings`, `proxy:status`, `proxy:backup`, `proxy:wal:archive`, `proxy:benchmark`, `proxy:feed:sync`)

## Menu Integration

Menu-hub surface per `telegram-platform-menu` contribution system (M-6 slice 2):
`/proxy` command (private chats only, tenant = bot owner) and
`ProxyInventoryHandler` (`GET inventory`, tenant = hub user). Masked counts only —
hosts and credentials never cross the bridge.

## Testing

```bash
# From module directory
composer test

# From root
vendor/bin/pest --testsuite ProxyModule
```

1152 tests, 3882 assertions. Architecture tests enforce 20 invariants (INV-001…INV-020).

## Structure

~372 source files, ~128 test files, 31 migrations, 5 languages (EN/RU/FR/ES/ZH).
