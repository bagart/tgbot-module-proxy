# Proxy Operations — план платформы

> Модуль `bagart/telegram-bot-proxy-module` (`BAGArt\ProxyOperations`) для Telegram bot platform.
> Репозиторий: https://github.com/bagart/telegram-bot-proxy-module
> Разработка ведётся внутри хост-платформы в `misc/BAGArt/telegram-bot-proxy-module`
> (composer path-repo, dev mode); prod — версионируемый пакет через `composer.prod.json`.
> Статус: план утверждён, реализация не начата. Дата: 2026-08-25.

## 1. Цель и скоуп

Платформа работы с прокси, интегрированная в Telegram (bot + Mini App + web-админка
поверх одного Application API):

- **проверка** прокси любых форматов: HTTP / HTTPS / SOCKS4 / SOCKS4a / SOCKS5 / SOCKS5h;
- **списки**: импорт (текст, файлы до 20 MB Bot API / Local Bot API large-file mode,
  CIDR-диапазоны), inventory, экспорт в TXT/CSV/JSON/config;
- **оценка качества и скорости**: latency p50/p95/p99 + jitter, bandwidth, анонимность,
  утечки, стабильность, гео/ASN, reputation;
- **пригодность для Telegram** (`[TG-CHECK]`): SOCKS5/HTTP — связность с DC Telegram
  через прокси (TCP+TLS); `mtproto://` — минимальный MTProto-handshake (`req_pq` →
  `resPQ`); парсер понимает `mtproto://` и `tg://proxy?...` ссылки (секреты
  hex/dd/+r, FakeTLS-домен).

**КРОМЕ VPN**: парсер явно отвергает схемы vless/vmess/trojan/ss/wireguard/openvpn
с понятной ошибкой (`[SCOPE]` non-VPN guard) — мусор не попадает в inventory.

## 2. Ключевые принципы

1. **Working ≠ Anonymous ≠ Safe ≠ Good** — ортогональные статусы, не сваливать в один score.
2. TXT-экспорт — **materialized view**, а не источник истины; источник — ProxyEndpoint.
3. Mini App — основной operational UI с v1; бот — алерты/команды/handoff.
4. Все три интерфейса поверх **одного Application API**.
5. **Multi-tenant везде** (см. §4).
6. Append-only observations, idempotency, durable runtime — с первого дня.
7. Politeness budgets: чекер никогда не DDoS-ит цели; egress IP чекера ≠ инфраструктурные.

## 3. Стек и интеграция с платформой

| Слой | Решение |
|---|---|
| Backend | Laravel 13, PHP 8.5, модуль-паттерн как nettools/antispam (composer path-repo в `misc/BAGArt/`) |
| Telegram | `bagart/telegram-bot-lib` (Bot API 9.5+) хост-приложения |
| Frontend | **React 19 + Inertia 2 + Tailwind 4 + Radix UI** (общий со платформой); Mini App — **@telegram-apps/sdk-react** |
| Транспорт | **`bagart/php-async-kernel-client`** — весь сетевой слой чекера (HTTP/SOCKS, DNS, сокеты) на его транспорте; асинхронные проверки на Fiber-based ASK runtime |
| Очереди | Redis Streams через `bagart/php-async-kernel-client-redis`; чего не хватает — дописываем в эту либу |
| Гео/ASN | Бесплатные mmdb (GeoLite2-совместимые зеркала / DB-IP Lite), скачивание скриптом при установке — НЕ в репо; provider contract для подключения лицензионного MaxMind |
| Bandwidth probe | `speed.cloudflare.com/__down` + собственные файлы судьи fallback |
| Судьи | Бесплатные публичные (Cloudflare trace — primary, icanhazip, checkip.amazonaws.com, ipify v4/v6; ifconfig.co исключён из дефолта — лимиты). Self-hosted `proxy-audit-judge` — после MVP (§10.12) |
| Reputation | Только бесплатные источники: зеркало Spamhaus DROP/EDROP из `firehol/blocklist-ipsets` (без регистрации); опц. AbuseIPDB free tier |
| Хранилище | PostgreSQL (дефолт платформы); проверенные прокси дублируются в универсальном формате (§10.12 п.20) |
| Инфраструктура | **Всё в Docker**: модули — в PHP-контейнере платформы; внешние программы — отдельные контейнеры (при необходимости с простым proxy-API постоянного контракта); **Tor — отдельный контейнер**; предпочитать программы с web API (§10.12 п.19) |
| Документация | OpenAPI (l5-swagger) для Application API + ADR |

**Политика «только бесплатные сервисы»**: внешний сервис принимается в план,
если у него есть бесплатный tier без обязательной регистрации; если регистрация
нужна и бесплатна — владелец регистрируется; если бесплатного аналога нет —
пишем свой (или берём opensource аналог). Все интеграции — за contract'ами,
чтобы готовые платные решения (MaxMind, коммерческие judges) подключались
позже без переписывания чекера.

## 4. Multi-tenancy — ОБЯЗАТЕЛЬНО ВЕЗДЕ

**Модель (решено 2026-08-26): tenant = пользователь платформы.**
У бота может быть множество юзеров; у каждого — свои списки прокси
(сохранение/загрузка), свой фронт-кэш. **1 пользователь = 1 workspace.**
Резолв: Mini App initData / chat id → user → его workspace (создаётся лениво
при первом действии). Ролевую матрицу Owner/Admin/Operator/Viewer упраздняем:
каждый workspace — единственный владелец (поле `role` в схеме зарезервировать
на будущее). Переусложнение не нужно.

- `tenant_id` (**workspace**) во **ВСЕХ** доменных таблицах с первого дня (IMPROVE#5):
  endpoints, credentials, sources, observations, pools, sessions, jobs, policies,
  incidents, metrics, exports, gateway log.
- Все запросы/репозитории/очереди/кэши/поиск — scope по tenant (server-side, тестируется).
- Webhook/commands/Mini App initData → резолв tenant до любой логики.
- Никаких глобальных сущностей вне тенанта, кроме системных справочников
  (judges, geo DB, reputation blocklists) и **общего backend probe-cache** (см. §5).
- Квоты вместо ролей: max proxies/job, jobs/day, concurrent jobs, file size — per-workspace.

## 5. Архитектура (выжимка из RFC-A/Ops/UX)

### Домен (RFC-A)
Центральные сущности: **ProxyEndpoint** (network identity: scheme/host/port) и
**ProxyAccess** (endpoint + credential). **Health/lifecycle/quarantine — на
ProxyAccess** (§11.35 п.1; `AccessState`:
`NEW → TESTING → WORKING → DEGRADED → FAILING → DEAD → RETIRED`,
ортогонально `TestabilityStatus` и `QuarantineStatus`). Endpoint-level state —
только derived summary. Credentials отдельной сущностью
(Endpoint/Credential/ProxyAccess, URI profiles, capability matrix).

### Чекер
- Транспорты: HTTP/HTTPS/SOCKS4/4a/5/5h; streaming файлов; mixed-format parser.
- Судьи: консенсус ≥2, exit IP v4/v6, headers/trace; **self-hosted judge**
  `/ip /echo /headers /trace /dns-token /health` + authoritative DNS canary.
- Judge trust: HMAC-подписи, TLS priority, HTTP-vs-HTTPS кросс-чек (tamper detection).
- Probes: liveness = **полный fetch тела** (анти stall-after-connect), anonymity tiers,
  header leak, marker probes (header integrity + cache detection),
  **UDP ASSOCIATE + DNS leak (TCP/UDP)** для SOCKS5 (`udp_support`),
  bandwidth (Cloudflare __down), **latency p50/p95/p99 + jitter** из серии проб
  (серия = **5 проб по умолчанию**, настраивается 1–50 per workspace/policy).
- Все сетевые проверки — асинхронные на транспорте `php-async-kernel-client`.
- **Shared backend probe-cache** (решено 2026-08-26): сырые пробы кэшируются
  глобально по ключу `(endpoint identity, judge set, probe type)` с TTL;
  интерпретация и scores — строго per-tenant. Экономит egress на дубликатах
  между workspace.
- **SSRF protection**: denylist private/loopback/link-local/metadata/cloud IPv4+IPv6,
  resolve-then-connect анти-DNS-rebinding, только фиксированные judges, лимиты body/
  connections, trusted-admin flag. (Паттерн `SsrfGuard` уже есть в nettools — переиспользовать.)
- **Governors**: центральные лимиты concurrency/rate/bandwidth (per-job/protocol/judge/global egress).
- Enrichment: бесплатные mmdb (country/city/ASN/TZ), скачивание при установке;
  **blacklist-скрининг** Spamhaus DROP/EDROP через зеркало `firehol/blocklist-ipsets`
  (без регистрации; опц. AbuseIPDB free tier) → reputation_score, авто-quarantine DROP.
- **Rotation detection**: N повторных замеров exit IP → unique_ip_count, тип ROTATING,
  набор exit identities → источник diversity.
- **[TG-CHECK] Пригодность для Telegram**: для SOCKS5/HTTP — TCP+TLS-соединение
  через прокси к DC Telegram (список DC IP:443/80 в конфиге, замер RTT per-DC);
  для **MTProto-прокси** (`mtproto://`, секреты hex / dd-префикс / +r restricted /
  FakeTLS-домен) — минимальный handshake `req_pq_multi` → `resPQ` на DC2:
  подтверждает, что прокси корректно релеит MTProto, без полной DH-авторизации.
  Флаги в модели и `verified_proxies`: `telegram_usable`, `tg_dc_rtt_ms`.
  Tor-контейнер (#82) проверяется тем же путём.

### Health & Scores (IMPROVE#1)
`capability_score` vs `health_score` раздельно, `formula_version` в истории;
TargetHealth / Target Profiles (per-target качество прокси), decay/freshness.

### Pools / Selection (Phase B/C)
STATIC/DYNAMIC/HYBRID, predicates+tags, one-click generation; Dynamic pool
materialization + decision log. ProxySelector: ROUND_ROBIN/LEAST_USED/RANDOM/
WEIGHTED + diversity (exit identity, geo/IP/network anti-correlation).

### Sessions / Resilience (Phase D)
Sticky sessions, rotation policies, reuse; CircuitBreaker, auto-recovery, quarantine,
cooldowns; ProxyLease + locks; durable runtime в Redis + lease-reaper + graceful drain.

### Scheduler / Fusion / Incidents (Phase E)
Jitter, adaptive intervals, canary probes, lazy checking, judge outage handling;
active+passive fusion; incident engine + action-oriented alerts (anti-flap гистерезис,
dedup, throttle Telegram progress edits 429); metrics buckets 5m/1h/1d; cost accounting.

### Gateway / Export / API (Phase F/G)
Proxy Gateway CONNECT/SOCKS c auth/ACL/routing rules; waterfall failover.
Export v2 health-aware + **protocol-priority dedup** (SOCKS5>SOCKS4>HTTPS>HTTP на хост).
Application API + CLI `proxy:*`. Policy engine YAML + presets + rollback.

### UX (RFC-UX)
Один frontend, два shell (bot chat ↔ Mini App ↔ web admin). Bottom nav
Home/Inventory/Pools/Jobs/More. initData validation серверно. Import Wizard →
Check → recommended pools; Pool Builder + Simulator; Get Proxy/N → Lease;
SelectionExplanation («Why skipped?»); SSE/WebSocket realtime + offline;
Dashboard + Global Search + Command Palette; роли Owner/Admin/Operator/Viewer.

### Новые Telegram API (`[TG-MODERN]`)
Rich Messages (Bot API 10.x) для отчётов/таблиц/кнопок с HTML-fallback;
date_time entities в расписаниях; DeviceStorage/SecureStorage; BiometricManager;
shareMessage (шеринг отчётов/пулов); fullscreen мониторинг; SecondaryButton;
hideKeyboard/disableVerticalSwipes/haptics; home screen shortcut; всё через
`isVersionAtLeast` feature detection; SDK — @telegram-apps/sdk-react.

### Конкурентный анализ → заимствования
monosans/proxy-scraper-checker (offline GeoLite2, full-fetch liveness, CIDR import),
mubeng (rotator/checker семантика), ProxyScrape desktop (protocol-priority export,
blacklist фильтры), Coronium (DNS leak), socks5-bench (p95 percentiles, rotation verify),
monosans/proxy-list + TheSpeedX + ErcinDedeoglu/proxies как built-in фиды (`[SOURCES-FEED]`).

## 6. Инфраструктура и SLO

- `[INFRA]` dockerized workers + autoscaling, zero-downtime деплой с drain долгих аудитов,
  secrets env/vault, queue backend Redis Streams/SQS.
- `[DB]` Партиционирование наблюдений по времени с первого дня; retention job
  опционален — **по умолчанию выключен** (решение «без удаления», §10.11).
- `[SLO]` selector p99 <50ms · webhook ack <200ms · throughput ≥150 proxies/min/worker
  (расчёт: concurrency 50 × аудит ~10s, §10.12) · gateway success rate ·
  uptime судей; алертинг по breach.
- `[IMPROVE#7]` Backup/PITR + полный экспорт инвентаря.
- `[P2-FUTURE]` Browser leak audit (Playwright worker) + multi-region checker fleet —
  интерфейсы зарезервировать.

## 7. План работ (94 задачи)

Выполнение идёт по субпланам в `docs/proxy-operations/tasks/` (README там —
индекс, статусы и правила для субагентов; Stage 0 разбит на файлы T00–T94,
стадии 1–11 — coarse-файлы `stage-NN-*.md`).

> **Важно (§11, раунд 4):** порядок работ задаёт §11.14 (Stage 0–11); §8 ниже —
> историческая диаграмма до консолидации. При конфликте приоритет у §11.

### Фаза 0 — фундамент
1. `[Refactor §113–115 RFC-A]` Центральная сущность ProxyEndpoint вместо ProxyCheckResult; audit = подсистема — HIGH
2. `[SCOPE]` Non-VPN guard: parser отвергает vless/vmess/trojan/ss/wireguard/openvpn с понятной ошибкой — HIGH
3. `[Phase A]` Inventory foundation: Endpoint/Credential/Source/Capability/Health/Observation + lifecycle — HIGH
4. `[Phase A]` Soft/hard-fail taxonomy — HIGH
5. `[Phase A]` Credentials: Endpoint/Credential/ProxyAccess, profiles, URI profiles, capability matrix — HIGH
6. `[RESTORED]` SSRF protection чекера (denylist, resolve-then-connect, fixed judges, лимиты, trusted-admin) — HIGH
7. `[RESTORED]` Credentials security (no-log, маскирование, envelope encryption + ротация, export opt-in, TTL) — HIGH
8. `[RESTORED]` Governors & квоты (concurrency/rate/bandwidth; квоты ролей) — HIGH
9. `[RESTORE]` Self-hosted proxy-audit-judge (/ip /echo /headers /trace /dns-token /health, VPS мультирегион, HMAC) — после MVP; bootstrap-период на публичных судьях (§10.11)
10. `[GEO]` Бесплатные mmdb (GeoLite2-совместимое зеркало / DB-IP Lite) primary + install-time скрипт скачивания (см. §10.6), еженедельное обновление; provider contract для MaxMind — HIGH
11. `[REPUTATION]` Spamhaus DROP/EDROP через `firehol/blocklist-ipsets` (без регистрации), опц. AbuseIPDB free tier, reputation_score, авто-quarantine — MEDIUM
12. `[ROTATE-DETECT]` Повторные замеры exit IP → unique_ip_count, тип ROTATING, exit identities — MEDIUM
13. `[PROBE-UDP]` SOCKS5 UDP ASSOCIATE + DNS leak detection + udp_support — MEDIUM
14. `[STALL]` Liveness = полный fetch тела — HIGH
15. `[LATENCY-PCTL]` p50/p95/p99 + jitter из серий проб; серия 5 по умолчанию, настройка 1–50 — MEDIUM
16. `[BANDWIDTH-CF]` speed.cloudflare.com/__down + файлы судьи fallback — MEDIUM

### IMPROVE-блок (сквозные требования)
17. `[IMPROVE#1]` capability_score vs health_score + formula_version — HIGH
18. `[IMPROVE#2]` Checker baseline self-test, NOT_TESTABLE, checker_region — HIGH
19. `[IMPROVE#3]` Judge trust: HMAC, TLS priority, HTTP-vs-HTTPS кросс-чек — HIGH
20. `[IMPROVE#4]` Marker probes: header integrity + cache detection — MEDIUM
21. `[NEW]` Politeness budgets: per-target rate limits, egress разделение, глобальный бюджет — HIGH
22. `[Phase B]` Health engine: health_score, TargetHealth/Target Profiles, decay, freshness — HIGH
23. `[IMPROVE#5]` tenant_id/workspace_id во всех таблицах с первого дня; tenant = user платформы, 1 user = 1 workspace, Owner-only (роль зарезервирована) — HIGH
24. `[IMPROVE#6]` Append-only observations + агрегация + schema_version — HIGH
25. `[IMPROVE#7]` Backup/PITR + полный экспорт инвентаря — HIGH
26. `[IMPROVE#8]` Idempotency: update_id dedupe, idempotency keys команд — HIGH
27. `[IMPROVE#9]` Durable runtime: Redis (через `php-async-kernel-client-redis`), lease-reaper, graceful drain, upstream pooling — HIGH
28. `[IMPROVE#10]` Dynamic pool materialization + decision log — HIGH
29. `[IMPROVE#11]` Анти-flap гистерезис + alert dedup + throttle progress edits (429) — HIGH
30. `[IMPROVE#12]` Testability: mock proxy containers, fixture-судьи, E2E canary, feature flags — HIGH

### Phase B–E — операции
31. `[Phase B]` Pools: STATIC/DYNAMIC/HYBRID, predicates, Tags/Groups, one-click — HIGH
32. `[Phase C]` Selection engine: ProxySelector, алгоритмы, weights, LB — HIGH
33. `[Phase C]` Diversity: exit identity, diversity_score, anti-correlation (+ ROTATE-DETECT) — MEDIUM
34. `[Phase D]` Sessions & rotation: sticky, policies, reuse — HIGH
35. `[Phase D]` Resilience: CircuitBreaker, auto-recovery, quarantine, cooldowns — HIGH
36. `[Phase D]` Leases & locks, concurrent limits, quotas — MEDIUM
37. `[Phase E]` Scheduler: jitter, adaptive, canaries, lazy checking, judge outage — HIGH
38. `[Phase E]` Active+passive fusion — MEDIUM
39. `[Phase E]` Target intelligence: reputation, affinity — MEDIUM
40. `[Phase E]` Incident engine & alerts — MEDIUM
41. `[Phase E]` Metrics: buckets 5m/1h/1d, gateway log, audit trail — MEDIUM
42. `[Phase E]` Cost accounting & provider comparison — LOW
43. `[Policy]` ProxyPolicy engine + YAML + presets + rollback — HIGH
44. `[Failover]` Waterfall, retry classification, method awareness — HIGH

### Phase F/G — доставка
45. `[Phase G]` Proxy Gateway: CONNECT/SOCKS, auth/ACL/routing — **P2-FUTURE** (вне MVP, условие запуска — появление консьюмера, см. §10.7)
46. `[Export v2]` Pool→TXT/CSV/JSON/config, health-aware + protocol-priority dedup — MEDIUM
47. `[API+CLI]` Общий application layer, CLI proxy:* — MEDIUM
48. `[Storage separation]` Разделение hot-метаданных (endpoints/scores/pools) и cold-наблюдений (observations/metrics) на разные таблицы/партиции + TTL наблюдений — MEDIUM
49. `[DB]` Партиционирование observations/probe_results + retention job (выкл. по умолчанию — «без удаления») — HIGH
50. `[INFRA]` Деплой: ASK-даэмоны-воркеры (graceful drain аудитов), zero-downtime деплой, secrets env/vault, Redis Streams queue backend — HIGH
51. `[SLO]` Измеримые цели + алертинг — MEDIUM
52. `[DOCS]` OpenAPI Application API + ADR — MEDIUM
53. `[P2-FUTURE]` Browser leak audit (Playwright) + multi-region fleet — интерфейсы зарезервировать — LOW

### Решения 2026-08-26 — новые задачи
76. `[ASK-TRANSPORT]` В `php-async-kernel-client`: поддержка прокси через конфиг — `ProxyConfig{scheme, host, port, credentials, tls, options}` + transport-specific options DTO, прокидывание через DTO/конфиг клиента; каждый транспорт валидирует совместимость по capability matrix (§11.4) — «один формат», но НЕ обещание универсальности (DNS-resolver поддерживает только SOCKS5/5h). **Все протоколы сразу** (HTTP CONNECT, SOCKS4/4a, SOCKS5 + UDP ASSOCIATE, SOCKS5h DNS semantics), UDP и DNS modes — раздельные концепты (§11.5); каждый протокол с тестами против mock-proxy-серверов — HIGH
77. `[GEO-FREE]` Install-time скрипт скачивания бесплатных mmdb (GeoLite2 Country/City/ASN с публичного зеркала или DB-IP Lite) в storage; НЕ хранить бинарники в репо; GeoProvider contract (mmdb primary → API fallback) — HIGH
78. `[REPUTATION-FREE]` Синк DROP/EDROP из зеркала firehol/blocklist-ipsets (git pull/raw), опц. AbuseIPDB free tier за contract'ом — HIGH
79. `[EGRESS]` Egress-стратегия: Phase A — единственный egress сервера хоста + self-hosted judges + politeness budgets; рост — Oracle Cloud Always Free региональные checker-ноды / Cloudflare WARP как второй egress; интерфейс CheckerNode/checker_region зарезервирован (IMPROVE#2) — MEDIUM
80. `[PROBE-CACHE]` Shared backend probe-cache: глобальный кэш сырых проб по версионированному `ProbeCacheKey` = schema_version + endpoint_identity + credential_fingerprint + checker_region + judge_set_version + probe_type + probe_profile_version (§11.7); секреты в ключ и значение не входят; интерпретация per-tenant — MEDIUM

### UX (RFC-UX)
54. `[UX §102]` ProxyOperationsModule: три интерфейса поверх одного Application API — HIGH
55. `[UX Ph1]` API-first контракт + DTO Resources — HIGH
56. `[UX Ph1]` Mini App auth: initData validation, session+CSRF, no secrets во frontend — HIGH
57. `[UX Ph1]` Webhook ingress async + conversation state — HIGH
58. `[UX Ph1]` Один frontend два shell + theme/MainButton + mobile-first/desktop — HIGH
59. `[TG-MODERN]` Bot API 10.x Rich Messages + Mini App 8.0–9.0 API + @telegram-apps/sdk-react (см. §5) — HIGH
60. `[UX Ph1]` Dashboard + Global Search + Command Palette — HIGH
61. `[UX Ph1]` Inventory UI: chips, saved views, bulk actions + Undo — HIGH
62. `[UX Ph2]` Import Wizard → Check → recommended pools (+ CIDR expansion с max IP limit) — HIGH
63. `[UX Ph2]` Audit Job UI: live results, pagination 10k+ (масштаб 1–10k, §10.11), virtual scroll — HIGH
64. `[UX Tandem]` Алерты action-oriented, deep links, MenuButtonWebApp, handoffs — HIGH
65. `[UX Ph3]` Pool Builder + Simulator + diversity preview + графики — HIGH
66. `[UX Ph3]` Get Proxy / Get N → Lease, Clipboard UX — HIGH
67. `[UX Ph4/6]` Editors: Sessions/Rotation/Gateway/Routing/Policy/Schedule — MEDIUM
68. `[UX Ph5]` Monitoring dashboard + Alerts настройки — MEDIUM
69. `[UX Explain]` Why-not-Elite / Why-skipped / SelectionExplanation — MEDIUM
70. `[UX Realtime]` SSE/WebSocket + fallback + offline — MEDIUM
71. `[UX Obs]` Метрики + доменные события — MEDIUM
72. `[UX Polish]` Empty states/wizard/disclosure/preferences/favorites — LOW
73. `[UX Acceptance]` Workspace/User модель: tenant = user платформы, 1 user = 1 workspace, Owner-only + tenant scoping тесты — HIGH

### Источники и финиш
74. `[SOURCES-FEED]` Built-in фиды: monosans/proxy-list (hourly), TheSpeedX, ErcinDedeoglu — delta-sync, дедуп; **квота фида = двухступенчатый чек**: дешёвая liveness-проба всех новых записей фида → детальный аудит только топ-N выживших (§10.11) — MEDIUM
75. `[Acceptance]` Финальный прогон: все фазы + IMPROVE + RESTORED + GEO/REPUTATION/UDP/RichMessages + SLO замеры — HIGH
81. `[WORKER-CONTRACT]` Чекер-воркер — отдельный контейнер в общем docker-compose платформы со строгим контрактом: задачи — Redis Streams (версионированные `AuditTask` V1, паттерн `fromJsonV1`), результат — `AuditResult` V1; воркер НЕ принимает доменных решений (lifecycle/score/pool/quarantine/lease) и не пишет в Postgres — только audit result/progress/worker state в Redis, домен применяет application layer (§11.9). HTTP control-API — только ops (`/health`, `/status/{job}`, `/jobs/{job}/cancel`). Реализация воркера заменяема без изменения контракта — HIGH
82. `[TOR]` Tor — отдельный Docker-контейнер (SOCKS5 :9050): транспорт/egress-опция чекера через конфиг (#76 единый формат proxy:), зарезервирован как источник — MEDIUM
83. `[ADMIN-SETTINGS]` Настройки админа в двух интерфейсах: web-админка + Telegram-форма (/settings wizard) поверх одного application layer; аудит изменений — HIGH
84. `[VERIFIED-STORE]` `VerifiedProxyProjection` в Postgres в универсальном формате v1 (§10.12 п.20, §11.15 п.1): **проекция, не вторая canonical model** — ProxyEndpoint единственный source of truth; обновляет один projector по `AuditCompleted`; включение по `VerifiedEligibilityPolicy` (capability-aware, access-based); внешний доступ — read-only (Postgres direct или Application API) — HIGH
85. `[TG-CHECK]` Пригодность прокси для Telegram: MTProto-парсер (`mtproto://`, `tg://proxy`, секреты hex/dd/+r/FakeTLS), минимальный handshake `req_pq_multi→resPQ` для MTProto, TCP+TLS до DC Telegram для SOCKS5/HTTP; модель `TelegramCompatibility{reachable, supported_transport, dc_results[], best_dc, median_rtt}`; `telegram_connectivity` ≠ гарантия клиента, `telegram_usable` — derived classification; выдача `tg://proxy` ссылок через `TelegramProxyUriFormatter` (не хранится canonical) (§10.12 п.22, §11.10) — MEDIUM
86. `[PARSER]` Парсер списков: грамматика одна — библиотека `ProxyOperations\Domain\Parsing`; MVP — внутренний application service (`ImportProxiesCommand` общий для bot/API/CLI/фидов); HTTP-адаптер `POST /parse` и контейнер `proxy-parser-svc` — P2 при появлении внешних потребителей; контракт ParserRequest/Response V1 фиксируется сразу; парсер не знает о шифровании (plaintext credential payload только внутри internal boundary) (§10.12 п.23, §11.13) — HIGH
87. `[ARCH-IDENTITY]` Stage 0 — identity-модель и протоколы: EndpointIdentity
    canonicalization (IDNA, IPv6 RFC 5952, default ports, trailing dot),
    CredentialFingerprint (HMAC-SHA256), AccessIdentity; ProxyProtocol enum +
    Transport/Application capability matrix (§11.2–11.5); DTO style
    (`final readonly` + SCHEMA_VERSION + fromJsonV1) — HIGH
88. `[ARCH-TAXONOMY]` Stage 0 — FailureTaxonomy V1: ось FailureClass
    PROXY/TARGET/JUDGE/CHECKER/PLATFORM/POLICY, атрибуты retryable /
    counts_as_failure / affects_health / affects_capability / quarantine;
    ExecutionFailure ≠ ProxyFailure (tool-коды TOOL_* не попадают в
    proxy_observations); ProbeProfile light/standard/deep/telegram/bandwidth
    (§11.16–11.17, §11.39 пп.12–14) — HIGH
89. `[ARCH-LIFECYCLE]` Stage 0 — AccessState machine + dimension-specific
    evidence types с applicability + pipeline Evidence → Health → Transition;
    VerifiedEligibilityPolicy; telegram freshness (§11.6, §11.35 пп.9–11,
    R6.3) — HIGH
90. `[ARCH-WIRE]` Stage 0 — wire-level контракты V1 (языко-независимые):
    AuditTask (immutable execution snapshot), AuditResult, ProbeExecution
    (§11.39 п.18); SealedCredentialPayload vs CredentialReference; tenant_id =
    metadata only; Job → Attempt → TaskDelivery — HIGH
91. `[ARCH-TOOL]` Stage 0 — External Tool boundary: контракт `ProbeTool`
    (+ ToolCapabilities/ProbeExecutionContext/ProbeToolResult), ToolRegistry +
    ToolManifest (allowlist tool_id → fixed executable), ProbeRunnerTransport
    (UnixSocket | HTTPS+mTLS), control plane ≠ execution plane, credentials
    никогда через argv (stdin/FD), Command Builder → execve без shell, resource
    governor spec, capability-specific контейнеры (§11.39) — HIGH
92. `[ARCH-CACHE]` Stage 0 — ProbeCacheKey v3 (endpoint_identity +
    credential_fingerprint + checker_node_id + egress_identity +
    judge_set_version + telegram_dc_set_version + probe_profile_version +
    probe_semantics_version + tool_semantics_version), value = allowlist raw
    evidence, idempotency split, event envelope + transactional ordering
    (§11.7, R6.2/R6.4, §11.19–11.20) — HIGH
93. `[ARCH-SNAPSHOTS]` Stage 0 — версионированные snapshot'ы: JudgeSetSnapshot,
    TelegramDcSet, AuditPolicySnapshot (= WorkspacePolicy разделение);
    SSRF policy split (ProxyEndpointConnectPolicy/JudgeConnectPolicy/
    TargetFetchPolicy); Contract Version Matrix (§11.35 пп.7–8, 13–14) — HIGH
94. `[ARCH-INARIANTS]` Stage 0 — Invariant Matrix INV-001…INV-020 как
    исполняемые arch-тесты + сводный ADR по решениям §§11.15/11.35/11.37/
    11.39; выход Stage 0 = контрактные классы + тесты инвариантов + ADR — HIGH

## 8. Порядок реализации

> **Устарело после §11 (раунд 4)** — актуальный порядок: Stage 0–11 в §11.14.

```
tenant-схема (IMPROVE#5 + UX Acceptance; tenant = user, 1:1 workspace)
  → Phase A inventory (+ SCOPE guard, SSRF, credentials security, governors)
    → ASK-TRANSPORT (#76) → parser/transports → judges+probes (+ GEO-FREE #77,
    STALL, LATENCY-PCTL) → PROBE-CACHE #80
      → health/scores → pools → selection → sessions/resilience
        → scheduler/fusion/incidents → export/API (gateway — вне MVP)
          → UX фазы 1–6 параллельно с бэкендом каждого слоя
```

## 9. Acceptance-критерии

- Прокси любого поддерживаемого формата импортируется; VPN-схемы получают понятный отказ.
- Полный цикл: импорт → аудит (консенсус судей, SSRF-safe) → scores → pools → lease → export.
- Каждый SQL-запрос домена scope по tenant (автотесты).
- SLO-метрики снимаются и укладываются в цели; алерты не флапают.
- Rich Messages рендерятся с HTML-fallback; Mini App работает на старых клиентах (feature detection).

## 10. Решения по открытым вопросам (2026-08-26)

### 10.1 Tenancy
Tenant = пользователь платформы; **1 user = 1 workspace**; Owner-only
(детали — §4). Ролевая матрица и маппинг bot→workspace не нужны.

### 10.2 Probe-cache
Общий backend-кэш сырых проб разрешён: ключ `(endpoint identity, judge set,
probe type)` + TTL; интерпретация/scores — per-tenant. Задача `[PROBE-CACHE]` #80.

### 10.3 Транспорт и очереди
Весь сетевой слой чекера — `bagart/php-async-kernel-client`, проверки асинхронные.
Задача `[ASK-TRANSPORT]` #76: единый формат включения прокси через конфиг для всех
адаптеров либы. Очереди — Redis Streams через `bagart/php-async-kernel-client-redis`;
нехватку операций дописываем в эту либу (никаких параллельных клиентов).
SQS исключён из скоупа. Воркеры — ASK-даэмоны хоста (graceful drain по
`ASKShutdownAware`, lazy connect через `warm()`).

### 10.4 Серии проб
Latency/jitter: серия из **5 проб по умолчанию**, настройка 1–50 per
workspace/policy (`[LATENCY-PCTL]` #15). Cost-риск снят фиксированной серией +
lazy checking + общим probe-cache.

### 10.5 Только бесплатные сервисы
Правило: бесплатный tier без обязательной регистрации → берём; регистрация
бесплатна → владелец регистрируется; бесплатного аналога нет → пишем свой или
берём opensource. Все интеграции за contract'ами для будущего апгрейда.
- **Reputation**: Spamhaus DROP/EDROP через зеркало `firehol/blocklist-ipsets`
  (обновляется ~12ч, без регистрации); опционально AbuseIPDB free tier
  (регистрация на владельца) — задача #78.
- **GeoIP**: см. 10.6.
- **Judges**: публичные бесплатные (Cloudflare trace — primary, icanhazip,
  checkip.amazonaws.com, ipify v4/v6; ifconfig.co исключён — лимиты) — консенсус
  ≥2 при смене статуса; self-hosted `proxy-audit-judge` — после MVP. Бюджеты и
  митигации лимитов — §10.12 п.1. Bootstrap: только публичные, politeness
  budgets обязательны с первого дня.

### 10.6 GeoIP без лицензий
Бесплатные mmdb скачиваются скриптом при установке в storage (НЕ в репо):
- источник A: зеркало GeoLite2 Country/City/ASN (`P3TERX/GeoLite.mmdb`, daily releases);
- источник B: DB-IP Lite (CC BY 4.0);
- еженедельное автообновление + SHA256 проверка;
- `GeoProviderContract`: mmdb primary → внешний API fallback (ip-api free tier);
  лицензионный MaxMind подключается той же contract'ом при необходимости.
Задача `[GEO-FREE]` #77.

### 10.7 Gateway — что это и зачем (reasoning)
Proxy Gateway = локальный HTTP CONNECT/SOCKS-сервер модуля: потребитель указывает
его как «обычный прокси», а модуль под капотом выбирает здоровый апстрим из пула
(rotation, failover waterfall, sticky sessions). Потребители — внешние приложения
пользователя, которым нужен «один адрес прокси на все нужды».
**Решение**: в MVP не входит (консьюмер внутри платформы отсутствует). Внутри-
платформенный кейс «модулю нужен выход через пул прокси» закрывается библиотечным
`ProxySelector` API (#32) без сетевого сервера. Standalone Gateway (#45) =
P2-FUTURE, запуск только при появлении реального консьюмера; тогда обязателен
hardening против open-relay (anti-bruteforce auth, per-user лимиты, SSRF-фильтры
на апстримы).

### 10.8 Rich Messages
Rich Messages — фича Bot API 10.x (часть протокола Telegram, бесплатна):
расширенные типы сообщений/разметка для отчётов и таблиц. Делаем (#59) с
HTML-fallback и feature detection (`isVersionAtLeast`) — на старых клиентах всё
работает как раньше. Никаких платных аналогов/самописной замены не требуется.

### 10.9 Egress IP
Своих IP нет. План:
- **Phase A**: egress = сервер хоста (единственный IP). Риск бана судьями при
  массовом чеке митгируется: primary судьи — self-hosted, politeness budgets (#21),
  распределение аудитов по времени (jitter scheduler).
- **Рост (задача #79)**: региональные checker-ноды на Oracle Cloud Always Free
  (несколько регионов бесплатно); Cloudflare WARP на отдельной ноде как второй
  egress (дёшево, но IP один и общий — только для разгрузки, не для diversity);
  интерфейсы `CheckerNode` / `checker_region` зарезервированы с IMPROVE#2.

### 10.10 MVP
MVP = готовый продакшен-продукт: Application API + бот, работающий в платформе
+ Mini App/web-админка на общем frontend'е. Полный цикл импорт → чек → scores →
пулы → lease → export. Проектируем схему и контракты сразу целиком (все фазы),
реализуем послойно без переписываний. Вне MVP: Gateway (#45), Browser leak audit
(#53), multi-region fleet, cost accounting (#42), self-hosted judge (#9).

### 10.11 Уточнения раунда 2 (обсуждение 2026-08-26)
- **Масштаб**: 1–10k прокси на workspace. Партиционирование остаётся, UI-пагинация
  10k+ (#63), retention «без удаления» — сырые observations храним вечно,
  retention job выключен по умолчанию.
- **Вертикальные срезы MVP** (вместо послойного наращивания интерфейсов):
  срез 1 = сквозной «импорт → чек → экспорт» во всех трёх интерфейсах
  (бот + Mini App + web, урезанно); далее углубление: пулы, сессии,
  мониторинг, политики.
- **Фиды с квотами = двухступенчатый чек**: stage 1 — дешёвая liveness-проба
  всех новых записей фида; stage 2 — детальный аудит только топ-N выживших.
  Отбор по качеству до полного аудита невозможен дешевле, чем лайт-чек.
- **Чекер-воркер — отдельный контейнер** в общем docker-compose платформы,
  строгий контракт (задача #81): очередь Redis Streams с версионированными
  JSON DTO + HTTP control-API (`/health`, cancel, статус). Реализация
  заменяема без изменения контракта.
- **Судьи bootstrap**: публичные бесплатные (Cloudflare trace — primary,
  icanhazip, checkip.amazonaws.com, ipify v4/v6), консенсус ≥2. Публичные судьи
  спуфятся самим прокси —
  поэтому маркер-пробы (#20) и HTTP-vs-HTTPS кросс-чек (#19) критичны уже
  в MVP, не опция. Self-hosted judge (#9) — после MVP. Бюджет судей — §10.12 п.1.
- **Транспорт (#76)**: все протоколы (HTTP CONNECT, SOCKS5 + UDP ASSOCIATE,
  SOCKS4/4a) одним майлстоуном, с mock-proxy тестами на каждый.

### 10.12 Проработка деталей (раунд 3, 2026-08-26)

**1. Судьи: бюджет и набор.** Дефолтный набор: Cloudflare trace (primary,
практически безлимитный), icanhazip.com (Cloudflare-owned, free),
checkip.amazonaws.com, api.ipify.org v4/v6. ifconfig.co исключён из дефолта
(жёсткие free-лимиты). Консенсус ≥2 — только при смене статуса и периодически;
рутинный liveness — 1 судья с ротацией. Суточный бюджет запросов на судью в
конфиге governors; при исчерпании — деградация до одного судьи + маркер-пробы
(анти-тампер компенсация). Self-hosted judge (#9) после MVP; дешёвая опция на
будущее — route `/pj/*` на хосте платформы (HMAC), отдельный VPS не нужен.

**2. Пороги lifecycle.** Успех пробы = HTTP <400 за connect ≤10s / total ≤20s,
тело получено. По серии (default 5): ≥3/5 → WORKING, 2/5 → DEGRADED, ≤1/5 →
FAILING. Между аудитами: 1 проваленный аудит подряд → ступень вниз; 2 подряд →
ещё ступень; 3 подряд → DEAD; любой успешный аудит → ступень вверх
(гистерезис #29). Auth-fail/refused классифицируются отдельно (fail taxonomy #4).

**3. Грамматика парсера.** Форматы строк: `host:port` · `scheme://[user:pass@]host:port` ·
`user:pass@host:port` · `host:port:user:pass` · `host port`. CSV/JSON — через
маппинг колонок в Import Wizard. Неоднозначность: scheme-префикс приоритетен;
`a:b:c:d` = host:port:user:pass если `a` — валидный хост и `b` ∈ 1–65535;
IPv6 без схемы — только в скобках `[::1]:8080`. CIDR expansion с лимитом квоты.

**4. Anonymity tiers.** elite — нет Via/X-Forwarded-For/Forwarded/X-Real-IP,
маркеры (#20) не просочились; anonymous — прокси-заголовки есть, реальный IP
не утёк; transparent — реальный IP в XFF/Forwarded. Источник — эхо заголовков.

**5. Bandwidth probe.** Default cap 1 MB, таймаут 10s, метрика bytes/sec по
фактическому объёму; настройка 100KB–10MB. Замеряется НЕ в каждом аудите:
еженедельно или on-demand (экономия трафика: 10k × 1MB = 10GB за полный проход).

**6. ProxyLease.** TTL default 300s, продление heartbeat'ом (паттерн
LeaseRenewer outbound), явное освобождение; истёкшие забирает lease-reaper.
1 активный lease на AccessIdentity (sticky-семантика;
`UNIQUE(active_lease, access_id)`).

**7. Расписание.** WORKING раз в 30m ±20% jitter; DEGRADED 15m; FAILING 10m;
DEAD backoff 24h→72h; RETIRED — manual/auto (30 дней безуспешно). Lazy checking:
Get Proxy при freshness > интервала → быстрый инлайн-чек перед выдачей.

**8. Версионирование контракта воркера.** Worker в `/health` отдаёт список
поддерживаемых версий DTO; платформа выбирает общую старшую; задача неизвестной
версии → DLQ с явной ошибкой. Внутри V1 — только additive изменения.

**9. ProxySelector API для модулей платформы.**
`ProxySelectorContract::acquire(criteria): Promise<?ProxyLease>` (Fiber await
для синхронно выглядящего кода потребителя); release/renew через lease DTO.
Замена gateway для внутриплатформенных консьюмеров.

**10. Docker-compose.** Сервис `proxy-checker-worker` в общей сети платформы;
Redis DSN через env; control-API только на internal порту (не наружу); секреты
env/vault. Judge HMAC secret — общий env платформы и воркера.

**11. Команды бота (MVP).** /start /help /import /list /check /stats /get /export
/settings + wizard-флоу импорта и выдачи. RU+EN.

**12. Web-доступ (решение владельца).** Вход в web-админку — magic-link через
бота (без пароля). Web-панель **по умолчанию недоступна** per-workspace;
включается флагом в админке (`web_panel_enabled`).

**13. Envelope encryption.** KEK — dedicated env `PROXY_ENC_KEY` (fallback:
derivation из APP_KEY); DEK на запись credentials, KEK только заворачивает DEK.
Ротация — команда re-wrap DEKов. Credentials никогда не логируются.

**14. Judge HMAC.** Заголовки `X-PJ-Timestamp`, `X-PJ-Signature =
HMAC-SHA256(secret, ts.".".sha256(body))`; anti-replay окно ±300s.

**15. Доменные события.** ProxyImported, AuditCompleted, AccessStateChanged,
EndpointQuarantined, PoolRebuilt, LeaseAcquired, LeaseReleased, CircuitOpened,
CircuitClosed, IncidentRaised, IncidentResolved, ExportGenerated.

**16. i18n.** Пять языков полностью с первого дня: **RU, EN, FR, ES, ZH**;
все строки через i18n-ключи.

**17. Экспорт «config».** TXT (варианты: ip:port · scheme://user:pass@host:port ·
host:port:user:pass), CSV, JSON (полная схема) + готовые конфиги инструментов:
proxychains.conf, curl -x snippet, Clash YAML.

**18. Throughput SLO.** ≥150 proxies/min/worker (консервативная цель: concurrency
50 × средний аудит ~10s); пиковая ~300/min. Пересчитать после бенчмарка #75.

**19. Docker-политика (решение владельца).** Всё в Docker. Модули платформы —
в PHP-контейнере хоста; **любые внешние программы — отдельные контейнеры**
(собственный docker-compose слой модуля). Если внешней программе нужен доступ
к домену — пишем для неё **простой proxy-API с постоянным контрактом**
(версионированный, additive-only), а не тащим её внутрь PHP. **Tor — отдельный
контейнер** (SOCKS5 :9050) — доступен чекеру как транспорт/egress-опция и как
источник для будущих нужд. При выборе сторонних инструментов предпочитать
программы с web API (не надо писать прокси над web-cli).

**20. Verified proxies — универсальное хранилище в Postgres.** Проверенные
прокси дублируются в таблицу `verified_proxies` (проекция — §11.15 п.1,
access-based, §11.37 R6.8) в универсальном формате v1:
`schema_version, tenant_id, access_id, endpoint_id, protocol,
credential_projection (masked), capabilities_projection, health_projection,
verification_policy_version, verified_at, last_checked_at`.
Включение — по `VerifiedEligibilityPolicy` (capability-aware: для MTProto —
handshake + TG connectivity; для HTTP/SOCKS health-порог может быть частью
policy, но не универсальный предикат).
Формат документирован как контракт: внешние контейнеры читают напрямую из
Postgres или через Application API; изменения — только additive (v1 → v2
добавляет поля). Обновляется асинхронно по `AuditCompleted`.

**21. Настройки админа — два интерфейса.** Административные настройки
(per-workspace квоты, флаги вроде `web_panel_enabled`, judge/governor бюджеты)
редактируются **и через web-админку, и через Telegram-форму** (/settings wizard).
Один application layer под обоими интерфейсами; изменения аудируются
(кто/что/когда). Задача #83.

**22. MTProto-прокси и проверка для Telegram (§1, [TG-CHECK], задача #85).**
MTProto-прокси — отдельный тип endpoint'а: это не SOCKS, обычные пробы к нему
неприменимы. Скоуп:
- парсер: `mtproto://<secret>@host:port`, `tg://proxy?server=&port=&secret=`,
  `https://t.me/proxy?...`; секреты: 16-byte hex, 17+ hex = FakeTLS (`ee` + домен),
  `dd`-префикс (padded), `+r`/short secret (restricted mode);
- чек MTProto: минимальный handshake `req_pq_multi` → `resPQ` через прокси на
  DC2 — достаточно для подтверждения релея MTProto, полная DH-авторизация не нужна;
- SOCKS5/HTTP не помечаются MTProto, но получают `telegram_usable` по связности
  с DC Telegram (TCP+TLS, RTT per-DC) — юзер видит «годится для TG-клиента»;
- выдача готовых `tg://proxy?...` ссылок в экспорте и боте.

**23. Сервис парсинга списков ([PARSER-SVC], задача #86).** Отдельный
контейнер `proxy-parser-svc` с одним назначением: на вход сырой текст/файл
любого формата прокси-листа (грамматика — §10.12 п.3, включая `mtproto://`
и `tg://proxy`), на выходе — **массив прокси-DTO** универсального формата v1:
`scheme, host, port, credential payload, source_line, parse_warnings[]`
(plaintext payload — internal-only `ParsedCredentialPayload`, §11.35 п.3;
`password_encrypted` в ответе парсера НЕТ — шифрование после парсера).
Контракт: один HTTP эндпоинт `POST /parse` (версионированный JSON,
additive-only, как #81/#84). Потребители: чекер-воркер (перед постановкой
задач в очередь), платформа (Import Wizard), любые внешние контейнеры.
Неразобранные строки не отбрасываются — возвращаются в `errors[]` с причиной
(включая non-VPN guard отказы). Реализация грамматики одна — библиотека в
модуле, обёрнутая в сервис; дублирования парсера нет.

**24. Стиль доменных и конфиг-DTO (обязательная конвенция).** Все proxy-DTO,
конфиг-DTO и контракты сообщений — по образцу outbound-DTO платформы
(`OutboundTask`, `DeadLetterEntry`):
- `final readonly class`, все свойства с **явно прописанными типами**
  (scalar/enum/вложенные readonly-DTO/DateTimeImmutable);
- значения задаются **только через конструктор** (property promotion);
  сеттеров и публичной мутабельности нет;
- `JsonSerializable` + `SCHEMA_VERSION` + именованный десериализатор
  `fromJsonV1()` (версионированный, additive-only);
- enums для перечислений (`ProxyScheme`, `AnonymityTier`, `EndpointStatus`);
- credentials в DTO — только маскированные/шифрованные представления,
  сырой пароль живёт в отдельном шифрованном объекте и в DTO не попадает.
Пример эталона: `telegram-bot-lib/src/Outbound/DeadLetterEntry.php`.

**25. Минимум env-зависимостей.** Настройки — в конфиг-файлах
(`config/proxy-operations.php` + вложенные структуры), не в env. Env — только
секреты (ключ шифрования, HMAC судей) и точки подключения (DSN Redis/Postgres);
env читается один раз на уровне конфига, доменная логика получает значения
через `config()` и readonly конфиг-DTO (п.24), напрямую `getenv()` не вызывает.
Набор env модуля держать минимальным и зафиксированным в одном месте.

## 11. Архитектурная консолидация (раунд 4, 2026-08-26)

Архревью выявило противоречия между разделами и риск дрейфа на стыках
#76/#80/#81/#84/#85/#86. Этот раздел — **высший приоритет при конфликте**
с более ранними формулировками. Главный принцип: не лечить противоречие
новым слоем абстракции — назначить существующему слою единственное владение.

### 11.1 Ownership / Boundary Matrix

| Ответственность | Владелец |
|---|---|
| Доменная модель прокси, грамматика парсинга, аудит-оркестрация | ProxyOperations |
| Парсинг: HTTP-адаптер (только если появится process boundary) | parser-svc (P2) |
| Транспорт HTTP/SOCKS/DNS | php-async-kernel-client (#76) |
| Async-планирование исполнения | async-kernel runtime |
| Исполнение аудита | checker-worker |
| Протокол очереди/воркера | worker contract (#81) |
| Персистентность, селекция, lease | ProxyOperations |
| Raw probe cache | ProxyOperations (инфраструктура кэша) |
| Geo / Reputation / Judge | provider contract'ы |
| Telegram UX, Web/Mini App | платформа/модуль |

**Worker — НЕ доменный слой**: получает `AuditTask` → строит transport config →
исполняет пробы → нормализует наблюдения → возвращает `AuditResult`.
Никаких решений о lifecycle, health score, pool membership, quarantine,
lease, selection, incidents внутри воркера.

### 11.2 Canonical identity model

Три уровня идентичности (устраняет неоднозначность dedup/cache/lease):

- **EndpointIdentity** = normalized `scheme + host + port` — один endpoint;
- **CredentialIdentity** = fingerprint credential'а (без самого секрета);
  у одного endpoint может быть несколько credential profiles;
- **AccessIdentity** = EndpointIdentity + CredentialIdentity — то, что
  реально проверяется и выдаётся.

Dedup импорта, probe-cache, leases, verified-projection строятся на AccessIdentity
(или EndpointIdentity для credential-free проб). `socks5://1.2.3.4:1080` и
`socks5://user:pass@…:1080` — разные AccessIdentity.
**Health/lifecycle/quarantine живут на ProxyAccess** (§11.35 п.1): endpoint с
разными credentials может иметь разное состояние; endpoint-level state — только
derived summary.

### 11.3 Credential model — protocol-specific

Единый `username/password` неверен. Модель:

```
ProxyCredential
 ├── BasicAuth        (HTTP/HTTPS)
 ├── SocksAuth        (SOCKS4/5 user/pass)
 └── MTProtoSecret    (hex / dd-padded / +r restricted / FakeTLS domain)
```

MTProtoSecret: шифрованное хранение, маскированное представление,
parser-normalized форма, валидация, отдельная export policy.
Envelope-шифрование (п.7 KEK→DEK) дополняется полями
`key_version, algorithm, nonce, ciphertext, tag`; ротация KEK =
rewrap DEK (перешифровка каждого пароля не нужна).

### 11.4 Protocol enum + Capability Matrix

`ProxyProtocol`: HTTP, HTTPS, SOCKS4, SOCKS4A, SOCKS5, SOCKS5H, **MTPROTO**.
Все probe types ссылаются на одну capability matrix:

| Capability | HTTP | HTTPS | SOCKS4 | SOCKS4a | SOCKS5 | SOCKS5h | MTProto |
|---|---|---|---|---|---|---|---|
| TCP connect | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| HTTP probe | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | — |
| Exit IP | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | protocol-specific |
| Header leak | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | — |
| UDP ASSOCIATE | — | — | — | — | ✓ | ✓ | — |
| DNS remote (SOCKS5h semantics) | — | — | — | — | ✓ | ✓ | — |
| MTProto handshake | — | — | — | — | — | — | ✓ |
| TG DC connectivity | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |

**#76 уточнён**: единый формат proxy-config (`ProxyConfig{scheme, host, port,
credentials, tls, options}` + transport-specific options DTO), но НЕ обещается
автоматическая совместимость «один config для всего» — каждый транспорт
валидирует совместимость сам по этой матрице. DNS через SOCKS5 ≠ «ещё одна
опция»: resolver поддерживает только SOCKS5/5h-режимы.

### 11.5 UDP vs DNS — раздельные концепты

Наличие UDP ASSOCIATE ≠ отсутствие DNS leak; SOCKS5h ≠ UDP ASSOCIATE.
Раздельные доменные концепты и поля:

- `UdpCapability` (udp_associate_supported);
- `DnsResolutionMode`: LOCAL_DNS / REMOTE_DNS / PROXY_DNS;
- `DnsLeakStatus` + `dns_resolution_path` + `dns_observation`.

### 11.6 Lifecycle — одна state machine, три ортогональных статуса

Комбинация DEAD+QUARANTINE+NOT_TESTABLE в одном enum взрывается. Разделить:

- `AccessState`: NEW → TESTING → WORKING → DEGRADED → FAILING → DEAD → RETIRED
  (живёт на **ProxyAccess**, §11.35 п.1 — переименование из EndpointState
  намеренное);
- `TestabilityStatus`: TESTABLE / NOT_TESTABLE;
- `QuarantineStatus`: NONE / QUARANTINED (+reason).

Одна машина переходов: `ProbeOutcome → HealthEvaluation → StateTransition`;
consecutive-failures — вход hysteresis, а НЕ вторая независимая машина.
`LifecyclePolicy` версионируется (`policy_version`, `formula_version`).

### 11.7 Observation → Interpretation pipeline

- **Raw observation** — immutable, глобально shared (latency=120ms — факт);
- **Tenant interpretation** — per-workspace (health_score зависит от policy);
  pipeline: Shared Observation → Tenant Interpretation → Health/Capability/
  Score/Lifecycle;
- **ProbeCacheKey V3** (заменяет ключ п.10.2; раунд 6 §11.37 R6.2):
  `schema_version + endpoint_identity (canonical) + credential_fingerprint
  (HMAC) + checker_node_id + egress_identity + judge_set_version +
  telegram_dc_set_version + probe_type + probe_profile_version +
  probe_semantics_version`. `checker_region` — metadata, не primary
  discriminator. Секреты в ключ не входят; кэш не содержит password/secret/
  auth-заголовков; value = safe raw evidence only (§11.37 R6.4);
- **Health vs Capability жёстко раздельно**: capability = что умеет (UDP/DNS/TG),
  health = насколько хорошо работает сейчас; health никогда не выводит capability
  (SOCKS5 без UDP со health=95 — норма);
- **Anonymity**: хранить сырые заголовки (`via_present, xff_present,
  forwarded_present, x_real_ip_present, real_ip_exposed, marker_modified`),
  `AnonymityTier` — derived interpretation (классификатор меняется без повторной пробы);
- **Metrics vs Observations**: observation immutable; метрики 5m/1h/1d — derived buckets;
- **TargetHealth[] отдельно от GlobalHealth** (Cloudflare 99 / Telegram 50 / Google 95).

### 11.8 Judges: trust model и узлы

- `JudgeDefinition {id, region, protocol, capabilities, rate_limit, trust_level}`,
  `JudgeProvider`, `JudgeObservation`, `JudgeTrustPolicy`;
- **HMAC защищает только self-hosted judge.** Публичные судьи не криптографически
  доверенные: их доверие = HTTPS + multi-judge agreement + marker probes +
  HTTP-vs-HTTPS кросс-чек (#19/#20). Убрать формулировки, где HMAC выглядит
  защитой публичных судей;
- **CheckerNodeContract**: каждая observation несёт `checker_node_id`,
  `checker_region`, `egress_identity`; MVP = node-1/local — задел на multi-region
  без смены схемы наблюдений.

### 11.9 Redis runtime vs Postgres domain truth; воркер и события

- **Postgres** = canonical domain truth; **Redis** = runtime (очередь, lease-lock,
  heartbeat, прогресс, job execution). Redis не доменная БД; потеря Redis
  не теряет домен;
- Worker пишет только audit result/progress/worker state; **домен изменяет
  application layer**: `AuditResult → transaction → Observation → Health →
  Lifecycle → Events`. Worker не пишет в Postgres напрямую;
- **Idempotency раздельно по источникам**: telegram_update_id (Telegram),
  Idempotency-Key (API), task_id+attempt_id (worker), event_id (events) —
  не взаимозаменяемы;
- **События трёх классов**: Domain (`AccessStateChanged`, `LeaseAcquired`,
  `PoolRebuilt`), Integration (`AuditCompleted`, `ExportGenerated`),
  Operational (`WorkerFailed`, `JudgeUnavailable`). Обёртка: `event_id,
  event_type, schema_version, occurred_at, tenant_id, aggregate_id, payload`;
- Control API воркера (#81) — только ops: `/health`, `/status/{job}`,
  `/jobs/{job}/cancel`. Не превращать в второй Application API;
- Lease создаёт application layer (Redis lock — concurrency primitive):
  acquire → atomic lock → Postgres state → runtime lease + recovery protocol.

### 11.10 Application Layer и интерфейсы

Interfaces (Bot/Mini App/Web/CLI) → Application Commands/Queries → Domain →
Infrastructure. Внутри одного deployment интерфейсы вызывают application services
**напрямую** — не обязаны ходить через HTTP сами к себе. Import Wizard, bot
/import, CLI, фиды используют один `ImportProxiesCommand`. Export читает
query/projection модель (`ExportQuery → ExportView → Formatter`), formatter'ы
(TXT/CSV/JSON/proxychains/curl/Clash/tg://) — адаптеры. `tg://proxy` ссылка
генерируется `TelegramProxyUriFormatter` из MtProtoEndpoint, не хранится
как canonical поле.

- **telegram_connectivity vs telegram_usable** (#85 уточнение): TCP+TLS до DC —
  это connectivity, не гарантия работы клиента; `TelegramCompatibility
  {reachable, supported_transport, dc_results[], best_dc, median_rtt, checked_at}`;
  `telegram_usable` — derived classification;
- Magic-link web-доступ (§10.12 п.17): one-time token → authenticated session;
  lifetime, single-use, binding, revocation, replay protection, rate limit;
  magic-link не становится долгоживущим API credential;
- Mini App auth flow: initData → TelegramInitDataVerifier → PlatformUser →
  WorkspaceResolver → ApplicationSession → CSRF; tenant_id никогда не приходит
  с frontend как authoritative;
- «Админ» = workspace owner (ролей нет); WorkspaceSettings ≠ SystemSettings;
- QuotaPolicy (workspace/job/checker/judge/egress limits) — данные; Governor —
  механизм enforcement, не хранилище политики;
- Scheduler знает WHEN, чекер HOW, application policy WHAT:
  Scheduler → AuditRequest → Worker → Probe (не Scheduler → SOCKS5 → Cloudflare);
- Audit job работает по **immutable PolicySnapshot** (`policy_version`),
  снимаемой при старте;
- **AuditTrigger**: MANUAL/SCHEDULED/IMPORT/FEED/LAZY_SELECTION/RECOVERY/TG_CHECK —
  для анализа стоимости и поведения.

### 11.11 Failure taxonomy, ProbeProfile, decision log, инциденты

- Единый каталог кодов (`DNS_FAILURE, TCP_TIMEOUT, TCP_REFUSED, TLS_FAILURE,
  AUTH_FAILURE, PROXY_PROTOCOL_ERROR, TARGET_4XX/5XX, BODY_STALL,
  JUDGE_UNAVAILABLE/INCONSISTENT, SSRF_BLOCKED, UNSUPPORTED_PROTOCOL,
  INVALID_CREDENTIAL, UDP_UNSUPPORTED, MTPROTO_HANDSHAKE_FAILED, RATE_LIMITED, …`)
  с атрибутами retryable / counts_as_failure / affects_health /
  affects_capability / quarantine / user_visible. Retry classification (#44)
  использует его;
- **ProbeProfile**: light / standard / deep / telegram / bandwidth — параметры
  проб собраны в профили; policy выбирает профиль (feed stage-1 = light,
  stage-2 = deep, lazy check = light, manual = standard/deep);
- Dynamic pool = projection (`materialization_version, generated_at,
  decision_log_id`); PoolMember не вторая source of truth;
- Decision log: candidate/predicate/reason_code/score/policy_version/timestamp,
  без credentials — «why skipped» отвечает без перезапуска selector;
- Incident — derived entity (fingerprint, dedup_key, severity,
  opened/last_seen/resolved, policy_version); anti-flap — в incident policy;
- Rotation detection: хранить `ExitIdentityObservation`, `RotationProfile`
  derived (отличать балансировщик / IPv4-v6 вариации / смену checker node);
  checker_region входит в интерпретацию.

### 11.12 Контрактные таблицы

Contract Version Matrix (единое версионирование вместо разрозненного):

| Contract | Version | Owner | Compatibility |
|---|---|---|---|
| AuditTask / AuditResult | V1 | ProxyOperations / Worker | additive |
| ParserRequest / ParserResponse | V1 | Parser | additive |
| VerifiedProxy projection | V1 | ProxyOperations | additive |
| DomainEvent envelope | V1 | ProxyOperations | additive |
| ProxySelector | V1 | ProxyOperations | semantic |
| Application API | V1 | Platform | versioned |

### 11.13 Изменения задач

**Изменяются:** #3 (+AccessIdentity), #4 (+Failure taxonomy), #5 (protocol-specific
credentials), #8 (Governors=enforcement, QuotaPolicy=данные), #13 (разделить UDP
и DNS), #14 (liveness = probe в составе профиля), #15 (+ProbeProfile), #17
(health/capability строго раздельно), #19 (trust semantics — HMAC только self-hosted),
#21 (Politeness Budget → QuotaPolicy), #22 (health engine = lifecycle state machine
единая), #23 (+defense-in-depth: scoped repositories, ownership checks, RLS где
возможно), #24 (+ExitIdentityObservation), #26 (idempotency раздельная), #27
(Postgres truth / Redis runtime), #28 (pool = projection), #29 (гистерезис в
lifecycle/incident policy), #31–36 (поверх canonical Endpoint/Access/Lease), #37
(scheduler без деталей проб), #38 (после observation model), #40 (incident derived),
#43 (PolicySnapshot), #44 (FailureTaxonomy), #46 (export = projection), #47
(application layer, не просто HTTP API), #48/#49 (observation/metrics + retention
policy явно off), #50 (worker runtime отделён от контракта), #74 (фид через общий
import pipeline: RawFeedEntry → Parser → NormalizedEndpoint → dedup → light → deep),
#80 (ProbeCacheKey v2, §11.7), #81 (worker без доменных решений), #84 (projection,
см. ниже), #85 (telegram_connectivity/compatibility model), #86 (см. ниже).

**Добавляются задачи (Stage 0 разбит на задачи разумного размера, §7 #87–94):**

87. `[ARCH-IDENTITY]` identity-модель + protocol/capability matrix + DTO style
88. `[ARCH-TAXONOMY]` FailureTaxonomy V1 (ось FailureClass) + ProbeProfile
89. `[ARCH-LIFECYCLE]` AccessState machine + dimension-specific evidence
90. `[ARCH-WIRE]` AuditTask/AuditResult/ProbeExecution wire contracts V1
91. `[ARCH-TOOL]` ProbeTool contract + ToolRegistry/Manifest + governor (§11.39)
92. `[ARCH-CACHE]` ProbeCacheKey v3 (+tool_semantics_version) + idempotency +
    event envelope/ordering
93. `[ARCH-SNAPSHOTS]` JudgeSet/DcSet/AuditPolicy snapshots + SSRF policy split
94. `[ARCH-INARIANTS]` INV-001…020 как arch-тесты + сводный ADR

(полные формулировки — §7; исполнение — субпланы `tasks/T00…T94`).

### 11.14 Порядок реализации (Stage 0–11, заменяет диаграмму §8)

```
Stage 0  Architecture contracts (#87–94) — tenancy, identity, protocol matrix,
         failure taxonomy (+FailureClass axis), probe profiles, observation
         model, judge/lifecycle/cache/idempotency/event contracts, wire-level
         worker/tool contracts + ProbeTool/ToolRegistry (§11.39), invariant
         arch-tests, DTO style
Stage 1  Core domain: Workspace, ProxyEndpoint, ProxyCredential, ProxyAccess,
         ProxySource, Capability, Observation, Health, Policy
Stage 2  Parser: ProxyParserContract (PHP) — host:port, scheme://, user:pass@,
         host:port:user:pass, host port, CIDR, mtproto://, tg://proxy + SCOPE guard
Stage 3  ASK transport (#76): HTTP CONNECT, SOCKS4/4a/5/5h + capability tests;
         отдельно UDP ASSOCIATE и DNS modes
Stage 4  Checker engine: ProbeProfile, ProbeExecutor, JudgeProvider,
         ObservationNormalizer (воркер только orchestration)
Stage 5  Audit pipeline: AuditJob(+trigger,+PolicySnapshot) → Attempt → Worker →
         AuditResult → Observation → Health → Lifecycle → Events
Stage 6  Cache: ProbeCacheKey v2 → ProbeCache → CachePolicy; shared cache ON здесь
Stage 7  Pools (projection) → Selector (+decision log) → Lease (recovery protocol)
Stage 8  Telegram compatibility (#85): TG DC connectivity, MTProto handshake,
         tg:// URI export
Stage 9  Projections: VerifiedProxyProjection (#84), export projections
Stage 10 API: Application Commands/Queries, Resources/DTO
Stage 11 Vertical MVP: /import → Parser → Inventory → Audit → Health → Pool →
         Lease → Export сквозь Bot + Mini App + Web + CLI
```

UX-фазы — параллельно бэкенду соответствующего слоя. Post-MVP (P2): dynamic pool
decision log UI, incident engine UI, advanced metrics, feed optimization,
multi-region fleet, Gateway, standalone parser-svc (если останется потребность).

### 11.15 Ключевые решения раунда 4 (изменяют прежние)

1. **verified_proxies — проекция, не вторая модель** (уточняет §10.12 п.20 и #84):
   ProxyEndpoint — единственный source of truth; `VerifiedProxyProjection`
   обновляется одним projector'ом по `AuditCompleted` (eligibility по
   VerifiedEligibilityPolicy, access-based);
   исчезновение из проекции = `verified=false`/удаление строки, не удаление endpoint;
   credentials rotation и tenant deletion отражаются; внешние контейнеры — только
   чтение.
2. **Parser: library-first** (уточняет §10.12 п.23 и #86): грамматика —
   `ProxyOperations\Domain\Parsing`, MVP использует внутренний application service;
   HTTP-адаптер `POST /parse` и отдельный контейнер — P2, только если появятся
   внешние потребители/crash isolation. Контракт ParserRequest/Response V1
   фиксируется сразу (Contract Matrix).
3. **Парсер не видит KEK/шифрования**: возвращает plaintext credential payload
   внутри internal boundary (in-process) либо по явному security contract'у;
   шифрование происходит после парсера: ParserDTO → CredentialNormalizer →
   CredentialEncryptor → Persistence. Внешний parser API без security contract
   credentials не передаёт.
4. **Экспорт credentials** — отдельное действие с explicit confirmation, audit,
   rate limit, no server-side logging; по умолчанию экспорт без plaintext.
5. **Backup**: критический — Postgres (domain truth, PITR); Redis — recoverable
   runtime, полный backup не требуется (это фиксирует §9/#25).
6. **Retention**: observations храним вечно, но партиционирование включено сразу
   для эффективных запросов/будущего retention/archival; `observation_retention_policy`
   существует, default disabled («без удаления» не противоречит партициям).
7. **i18n/domain**: домен возвращает error codes (`PROXY_INVALID_PORT`), локаль
   применяется только в presentation; пять языков (§10.12 п.16) не меняются.
8. **Критерий готовности плана**: для каждого ключевого объекта однозначно
   отвечаются 7 вопросов (владелец, source of truth, кто создаёт/меняет/читает,
   версионирование, поведение при сбое); для каждого сетевого действия — transport/
   protocol/probe/judge/checker node/кэш/raw→interpretation путь. Финальная
   consistency-проверка документа — обратным проходом по всем осям (Domain,
   Infrastructure, Interfaces, Security, Reliability, Performance 10–10k endpoints,
   Protocols, Lifecycle, Derived data).

### 11.16 Failure Taxonomy — полный каталог

Единый код ошибки возвращается любой пробой; атрибуты управляют retry, health,
capability и UI. Домен отдаёт только коды, локаль применяется в presentation
(§11.15 п.7).

| Код | retryable | counts_as_failure | affects_health | affects_capability | quarantine | user_visible |
|---|---|---|---|---|---|---|
| DNS_FAILURE | ✓ | ✓ | ✓ | dns | — | ✓ |
| TCP_TIMEOUT | ✓ | ✓ | ✓ | — | после N | ✓ |
| TCP_REFUSED | ✓ | ✓ | ✓ | — | после N | ✓ |
| TLS_FAILURE | partial | ✓ | ✓ | https | — | ✓ |
| AUTH_FAILURE | — | ✓ | ✓ | auth | ✓ | ✓ |
| PROXY_PROTOCOL_ERROR | — | ✓ | ✓ | scheme | ✓ | ✓ |
| TARGET_4XX | — | context | context | — | — | ✓ |
| TARGET_5XX | ✓ | context | context | — | — | ✓ |
| BODY_STALL | ✓ | ✓ | ✓ | liveness | — | ✓ |
| JUDGE_UNAVAILABLE | ✓ | — | — | — | — | ✓ |
| JUDGE_INCONSISTENT | ✓ | — | — | anonymity | — | ✓ |
| SSRF_BLOCKED | — | — | — | — | ✓ | ✓ |
| UNSUPPORTED_PROTOCOL | — | — | — | scheme | — | ✓ |
| INVALID_CREDENTIAL | — | ✓ | ✓ | auth | ✓ | ✓ |
| UDP_UNSUPPORTED | — | — | — | udp | — | ✓ |
| MTPROTO_HANDSHAKE_FAILED | ✓ | ✓ | ✓ | mtproto | — | ✓ |
| RATE_LIMITED | ✓ (backoff) | — | — | — | — | ✓ |

`context` для TARGET_4XX/5XX: не вина прокси — влияет на health мягко,
не на capability. «после N» = quarantine по порогу consecutive failures из
LifecyclePolicy. Каталог расширяемый, но новые коды проходят через ADR
(атрибуты обязательны).

**Ось FailureClass (раунд 7, §11.39 пп.12–13).** Каждому коду присваивается
класс ответственности — атрибуты становятся однозначными:

| FailureClass | Примеры кодов | Смысл |
|---|---|---|
| PROXY | TCP_TIMEOUT, TCP_REFUSED, AUTH_FAILURE, TLS_FAILURE, MTPROTO_HANDSHAKE_FAILED | вина прокси |
| TARGET | TARGET_4XX, TARGET_5XX | вина целевого сайта |
| JUDGE | JUDGE_UNAVAILABLE, JUDGE_INCONSISTENT | вина судьи |
| CHECKER | TOOL_TIMEOUT, TOOL_CRASH, TOOL_PROTOCOL_ERROR, TOOL_OOM, TOOL_EXIT_FAILURE, TOOL_OUTPUT_INVALID, TOOL_UNAVAILABLE | вина checker-инфраструктуры |
| PLATFORM | REDIS_UNAVAILABLE, STORAGE_UNAVAILABLE | вина платформы |
| POLICY | SSRF_BLOCKED, UNSUPPORTED_PROTOCOL | отклонено политикой |

**ExecutionFailure ≠ ProxyFailure.** ProbeExecutionResult: success →
Observation; execution failure → ExecutionFailure (класс CHECKER/PLATFORM).
TOOL_* коды **никогда** не пишутся в `proxy_observations` как proxy
observation и **не изменяют proxy health**: таймаут чекера — это не таймаут
прокси. InfrastructureFailure учитывается в метриках checker node (#51) и в
retry/DLQ логике доставки, а не в интерпретации прокси.

### 11.17 ProbeProfile — профили проб

Параметры проб собраны в именованные профили; policy/workspace выбирает профиль,
а не разрозненные настройки. Профиль версионируется (`probe_profile_version`
входит в ProbeCacheKey).

| Параметр | light | standard | deep | telegram | bandwidth |
|---|---|---|---|---|---|
| Судей | 1 (ротация) | 2–3 | все доступные | DC-focused | 1 |
| Серия latency | 3 | 5 | 5 | 3 | 1 |
| Bandwidth cap | — | — | — | — | 1 MB |
| Header/marker пробы | — | ✓ | ✓ | — | — |
| Anonymity headers | — | ✓ | ✓ | — | — |
| UDP/DNS пробы | — | opt-in | ✓ | — | — |
| TG DC connectivity | — | opt-in | opt-in | ✓ (все DC) | — |
| MTProto handshake | n/a | n/a | n/a | для MTPROTO | — |
| Timeout | агрессивный | стандартный | щедрый | стандартный | щедрый |
| Стоимость (отн.) | ~0.3× | 1× | ~2.5× | ~1.5× | ~1.2× |

Назначение: feed stage-1 → light; feed stage-2 → deep; scheduled WORKING →
light/standard; manual audit → standard (deep по кнопке); selection lazy-check →
light; TG-check → telegram; weekly → bandwidth.

### 11.18 AuditJob: trigger, snapshot, модель

- **AuditTrigger**: `MANUAL, SCHEDULED, IMPORT, FEED, LAZY_SELECTION, RECOVERY,
  TG_CHECK` — пишется в каждый job; агрегируется для cost accounting (#42) и
  анализа поведения.
- **Job model**: `job_id, tenant_id, trigger, policy_snapshot_id, requested_by,
  created_at, started_at, completed_at, status`; attempts — отдельные строки
  (`attempt_id`, worker_node, started/finished, result_code).
- **Immutable AuditPolicySnapshot** (`policy_snapshots`): при старте job
  снимается только то, что может изменить результат job — probe profile,
  lifecycle thresholds, judge set, targets, governors, retry policy +
  `policy_version`. Retention/UI flags/export rules в снапшот НЕ входят
  (это `WorkspacePolicy`, §11.35 п.7). Job живёт по снапшоту: изменение
  настроек workspace посреди аудита не влияет на уже идущий job.
- **Job → Attempt → TaskDelivery** (§11.37 R6.7): `AuditJob` = logical user
  intent; `AuditAttempt` = одна попытка исполнения; `AuditTask` = одно
  сообщение доставки воркеру. Retry создаёт attempt (и новый delivery),
  но НЕ новый job; task_id не является identity attempt'а.
- Идемпотентность постановки: дубликат job по `(tenant_id, trigger,
  target_set_hash, policy_snapshot_id)` в окне TTL не создаётся повторно.

### 11.19 Idempotency — раздельная по источникам

Ключи разных источников НЕ взаимозаменяемы и хранятся раздельно:

| Источник | Ключ | Хранилище dedup | TTL |
|---|---|---|---|
| Telegram updates | `telegram_update_id` | processed-updates set | 24h |
| Application API | `Idempotency-Key` header | таблица api_idempotency | configurable |
| Bot commands | update_id + command hash | как API | 24h |
| Worker results | `task_id + attempt_id` | attempts unique constraint | вечность |
| Domain events | `event_id` (ULID) | events PK | вечность |
| Import batches | batch hash (normalized input) | import_batches | configurable |

### 11.20 События: envelope и каталоги

Envelope (единый для всех классов): `{event_id, event_type, schema_version,
occurred_at, tenant_id, aggregate_id, payload}` — readonly DTO, additive-only.

- **Domain events** (внутренние, меняют состояние): `AccessStateChanged`,
  `CredentialRotated`, `LeaseAcquired`, `LeaseReleased`, `PoolRebuilt`,
  `QuarantineChanged`, `PolicyUpdated`.
- **Integration events** (для консьюмеров/проекций): `AuditCompleted`,
  `ExportGenerated`, `ImportBatchProcessed`, `FeedSynced`.
- **Operational events** (ops/alerting): `WorkerFailed`, `WorkerHeartbeatMissed`,
  `JudgeUnavailable`, `JudgeBudgetExhausted`, `GovernorThrottled`,
  `CachePoisoningSuspected`.

Доставка: outbox-паттерн в Postgres → диспетчер (at-least-once);
консьюмеры обязаны быть идемпотентны по `event_id`.

### 11.21 Модель БД — минимальный набор таблиц

Для каждой таблицы определены владелец записи, чтение, tenant и lifecycle.
Не создавать таблицу «потому что концепция существует».

| Таблица | Source of truth | Пишет | Читает | tenant_id |
|---|---|---|---|---|
| workspaces (= users платформы, 1:1) | platform | platform | всё | self |
| proxy_endpoints | да | application layer | всё | NOT NULL |
| proxy_credentials | да | application layer (CredentialService/encryptor) | application layer; sealed-execution builder | NOT NULL |
| proxy_accesses | да | application layer | selector, export | NOT NULL |
| proxy_sources | да | import pipeline | UI | NOT NULL |
| raw_feed_entries | staging | feed syncer | import pipeline | NOT NULL |
| policy_snapshots | да (immutable) | job starter | worker, auditor | NOT NULL |
| proxy_policies | да | owner (settings UI/bot) | snapshot builder | NOT NULL |
| proxy_observations | да (append-only) | application layer (из AuditResult) | health engine, metrics | NOT NULL |
| proxy_health | derived, **access-scoped** | health engine | selector, UI | NOT NULL + access_id |
| proxy_capabilities | derived (**endpoint-level** protocol capability + **access-level** auth/udp/dns/tg) | capability evaluator | selector, UI, export | NOT NULL (+access_id для access-level части) |
| proxy_pools | да | application layer | selector, UI | NOT NULL |
| proxy_pool_members | projection | materializer | selector | NOT NULL |
| proxy_leases | да (state в Postgres, lock в Redis), **per AccessIdentity** | selection layer | reaper, UI | NOT NULL + access_id |
| proxy_audit_jobs / _attempts | да | application layer | worker (через контракт), UI | NOT NULL |
| proxy_incidents | derived, **access-scoped** | incident engine | alerts, UI | NOT NULL + access_id |
| proxy_exports | да (метаданные выгрузок) | exporter | download | NOT NULL |
| proxy_events | да (append-only, outbox) | dispatcher | projections, webhooks | NOT NULL |
| verified_proxies | **проекция** | единственный projector | внешние контейнеры read-only | NOT NULL |

Глобальные (без tenant): judge_definitions, judge_set_snapshots, geo_datasets,
reputation_datasets, checker_nodes, system_feed_definitions, feed_sync_runs,
telegram_dc_sets. Партиционирование: `proxy_observations` (по времени),
`proxy_events` (по времени); индексы — `(tenant_id, ...)` ведущий во всех
tenant-таблицах; observations: `(tenant_id, access_id, checked_at DESC)` +
`(access_id, checked_at DESC)` для shared-чтения. Retention policy существует
в конфиге, default disabled («без удаления»), партиции включены сразу ради
эффективных запросов и будущего archival.

### 11.22 Tenant isolation — defense-in-depth

Три уровня, каждый тестируется:

1. **Application**: tenant-scoped repositories — запросы без явного scope
   невозможны (базовый builder всегда требует tenant context).
2. **Domain**: ownership checks на entity-уровне (lease/pool/export проверяют
   принадлежность access → endpoint → tenant цепочкой).
3. **Database**: Postgres RLS на критических таблицах (`proxy_endpoints`,
   `proxy_credentials`, `proxy_accesses`, `verified_proxies`) либо тестируемые
   CHECK/constraints + composite FK `(id, tenant_id)` в дочерних таблицах.

Правила колонки: tenant-owned → `NOT NULL`; глобальные справочники → нет;
дочерние сущности дублируют `tenant_id` даже при выводимости через FK — это
разрешает repository-level assertions и RLS. `tenant_id` никогда не принимается
из пользовательского ввода как authoritative — только из аутентифицированного
контекста (initData/session/API key).

Probe-cache security: ключ не содержит секретов; значение не содержит
password/secret/auth-headers; poisoned observations детектируются кросс-чеком
(#19/#20) и помечаются operational event'ом; stale — по TTL профиля.

### 11.23 Credentials: шифрование, ротация, экспорт

Envelope: KEK (env `PROXY_ENC_KEY`, только env) → DEK (per-workspace) → поля.
Формат зашифрованного значения: `{key_version, algorithm, nonce, ciphertext,
tag}`. Ротация KEK: unwrap всех DEK старым KEK → rewrap новым (перешифровка
паролей не требуется); `key_version` позволяет ленивую миграцию чтения.
MTProtoSecret — тот же конверт, отдельный тип.

Экспорт: default — без plaintext credentials (маскированные). Отдельное действие
«Export with credentials»: explicit confirmation в UI/боте, audit-запись
(кто/что/сколько), rate limit, no server-side logging содержимого.

### 11.24 Lease: жизненный цикл и восстановление

Создание: selection layer выбирает candidate → freshness check (свежесть health;
если stale — optional light audit request) → atomic lock в Redis
(`SET NX PX`) → запись state в Postgres (`proxy_leases`: holder, expires_at,
purpose) → runtime lease для consumer.

Recovery после краша consumer'а: lease-reaper (IMPROVE#9) возвращает истёкшие
leases в пул; holder обязан продлевать heartbeat (TTL 300s, §10.12 п.5).
Один lease на AccessIdentity. При потере Redis — leases считаются утраченными,
Postgres state остаётся источником для reconciliation при восстановлении.

### 11.25 Selection, pools, decision log

Flow: `SelectionCriteria → candidate query (pool predicate) → freshness/lazy-check →
reevaluate → ProxyLease`. Selector не делает сетевых проверок кроме разрешённого
lazy-check (light профиль).

Dynamic pool = projection: `PoolDefinition{predicate} → candidate query →
materialized members` c `materialization_version, generated_at, decision_log_id`.
PoolMember не вторая source of truth — пересборка воспроизводима.

Decision log (объяснимость «Why skipped»): `{candidate, predicate, decision,
reason_code, score, policy_version, timestamp}` — без credentials. Ответ на
«почему не выбран» читается из лога, без перезапуска selector.

### 11.26 Инциденты и метрики

Incident — derived entity: observations/state transitions → incident rules →
incident `{fingerprint, dedup_key, severity, opened_at, last_seen_at, resolved_at,
policy_version}`. Anti-flap (hysteresis, dedup) — в incident policy, не в
scheduler. Alert dedup + throttle progress edits (429) — infrastructure concern:
`JobProgressEvent → notification adapter → throttler → editMessage`; домен не
знает про Telegram.

Metrics: observation immutable; метрики 5m/1h/1d — derived `MetricBucket`
(агрегатор по `AuditCompleted`). Gateway log и audit trail (#41) — отдельные
append-only структуры.

Rotation detection: хранить `ExitIdentityObservation{checked_at, exit_ip,
checker_node, checker_region}`; `RotationProfile` derived — различать rotating
прокси / балансировщик / IPv4↔IPv6 вариации / смену egress checker'а
(checker_region входит в интерпретацию).

TargetHealth[] отдельно от GlobalHealth: один прокси может иметь
Cloudflare=99 / Telegram=50 / Google=95. `TargetProfile` — конфигурационный
контекст запроса (какие цели проверяем), не смешивается с глобальным health.

### 11.27 Scheduler: WHEN / HOW / WHAT

Scheduler отвечает только за WHEN: интервалы (30m/15m/10m/backoff 24h→72h,
jitter ±20%), приоритеты очередей, canary-расписание. Он не знает деталей проб:
`Scheduler → AuditRequest(trigger, profile, targets) → очередь → Worker`.
HOW — чекер/воркер; WHAT (какие пробы, судьи, бюджеты) — application policy
из PolicySnapshot. Judge outage: scheduler понижает профиль до light и
переносит deep-аудиты (см. §10.12 п.1).

### 11.28 Фиды и импорт — единый pipeline

```
Feed → RawFeedEntry (staging, append-only)
     → Parser (Domain\Parsing) → NormalizedEndpoint/Access DTO
     → dedup (AccessIdentity) → cheap light liveness (stage-1)
     → top-N survivors → deep audit (stage-2) → Inventory
```

Фид — просто Source; entry не становится ProxyEndpoint автоматически.
Импорт откуда угодно (bot /import, Import Wizard, API, CLI, фид) — один
application command `ImportProxiesCommand(paste|file|feed, policy_snapshot)`;
различается только presentation. CIDR expansion — на этапе normalized DTO
с max IP limit (#62).

Экспорт: `ExportQuery → ExportView (projection) → Formatter` (TXT/CSV/JSON/
proxychains/curl/Clash/tg://) — formatter'ы чистые адаптеры. CLI
`proxy:inventory:export` и UI используют один `InventoryExporter`.

`tg://proxy` URI: генерируется on-the-fly `TelegramProxyUriFormatter`
(protocol-aware: secret encoding, FakeTLS domain, restricted secret,
URL-encoding) из MtProtoEndpoint; никогда не хранится как canonical поле.

### 11.29 Интерфейсы: сессии, magic-link, прогресс

- **Mini App auth chain**: initData → `TelegramInitDataVerifier` (HMAC, окно
  свежести) → PlatformUser → WorkspaceResolver (1:1) → ApplicationSession →
  CSRF token. Никаких secrets во frontend; tenant_id не из клиента.
- **Magic-link** (web-доступ, §10.12 п.17): one-time token → authenticated web
  session. Спецификация: lifetime ≤ 15m, single-use (таблица consumed), binding
  к user+workspace, revocation владельцем, replay protection (consumed-at),
  rate limit выдачи (per-user), audit. Magic-link не становится долгоживущим
  API credential.
- **Настройки**: `WorkspaceSettings` (owner, оба интерфейса, аудит изменений)
  ≠ `SystemSettings` (платформа, только web-админка системного админа).
  `QuotaPolicy` — данные лимитов (workspace/job/checker/judge-budget/egress);
  Governor — enforcement-механизм, не хранилище. Politeness budgets (#21) —
  часть QuotaPolicy.
- **i18n**: домен возвращает коды (`PROXY_INVALID_PORT`); локаль применяется
  только в presentation; пять языков RU/EN/FR/ES/ZH (§10.12 п.16).

### 11.30 Worker: декомпозиция рантайма

Пересмотрено в раунде 7 (полная модель — §11.39): граница исполнения —
четырёхуровневая, Worker Gateway не становится domain layer.

```
Scheduler (PHP, WHEN)  →  Audit Worker  →  Probe Runner  →  External Tool
```

- **Scheduler** — WHEN (§11.27): какой job, когда, с каким снапшотом.
- **Audit Worker** — какую задачу выполнить, какие probe запустить, как собрать
  результаты, как обработать timeout/crash:
```
Worker Node
 ├── Control Plane      GET /health /ready /metrics /capabilities (internal)
 ├── Execution Plane    Queue Consumer → Audit Executor → Probe Planner →
 │                      Probe Runner(s) → Result Normalizer → Reporter
 ├── Tool Runtime       HTTP/SOCKS/DNS/TCP-TLS/MTProto tools за ProbeTool contract
 └── Security Boundary  Resource Governor · Process Supervisor · Output Limits ·
                        Timeout Enforcement · Secret Zeroization
```
- **Probe Runner** — как конкретно вызвать инструмент (единый Probe Execution
  Protocol, transport UnixSocket|HTTPS+mTLS).
- **External Tool** — конкретная сетевая операция; доменных сущностей не знает.

Примеры цепочек: `AuditWorker → Socks5ProbeRunner → curl/binary`;
`AuditWorker → MtprotoProbeRunner → mtproto-checker binary`.

Worker пишет в Redis: audit result, progress, heartbeat, job execution state.
Доменные таблицы Postgres воркер не трогает (§11.9). Контракты `AuditTask` /
`AuditResult` / `ProbeExecution` V1 — wire-level и языко-независимые:
реализация воркера заменяема на не-PHP без изменения контракта.

Redis contents inventory (что допустимо): queue streams, lease locks,
heartbeats, progress, job execution state, DLQ, metric counters, raw probe cache.
Запрещено: доменные решения, соединения/closures/behavior-объекты (конвенция
платформы «readonly DTO в Redis»).

### 11.31 Backup / PITR / отказоустойчивость

- **Критический backup: Postgres** (domain truth) — PITR обязателен (#25).
- **Redis**: recoverable runtime — потеря приемлема (очередь пере-наливается из
  pending jobs, leases reconciliate из Postgres, кэш прогревается). Полный backup
  Redis не требуется — это осознанное решение, фиксирующее §9/#25.
- Failure modes явно: worker crash (attempt → retry/DLQ по таксономии), judge
  outage (профиль light, перенос deep), Redis outage (пауза постановки,
  reconciliation), Postgres outage (worker приостанавливается, буферизация
  результатов в Redis с последующей досылкой).

### 11.32 SLO — методика замера вместо placeholder

SLO «≥150 proxies/min/worker» — minimum guaranteed throughput на зафиксированном
профиле нагрузки, а не архитектурное предположение. Benchmark matrix (#75):

- concurrency: 10 / 25 / 50 / 100;
- судей: 1 / 2 / 5 / 10;
- протоколы: HTTP, HTTPS, SOCKS5, SOCKS5h, SOCKS4, MTProto;
- mix success/failure: 100% ok / 50% fail / 90% fail (timeout-heavy).

Измеряется: throughput (successful audits/min), CPU, RAM, Redis ops/s, network,
latency p50/p95/p99, error rate. Результат фиксирует целевой профиль
(например «50 concurrency × 2 judge × mixed 70/30») и гарантированный минимум;
алерты (#51) ставятся от измеренного значения, не от оценки.

Масштаб данных: производительность проверяется на 10 / 100 / 1k / 10k endpoints
на workspace (пагинация #63, virtual scroll, materialized pools).

### 11.33 Финальная consistency-проверка (критерий завершения раунда)

Обратный проход по документу перед стартом реализации. Для каждого ключевого
объекта — 7 вопросов: кто владеет; где source of truth; кто создаёт; кто
изменяет; кто читает; как версонируется; что при сбое/повторе. Для каждого
сетевого действия: transport; protocol; probe; judge; checker node/egress;
что кэшируется; путь raw observation → tenant interpretation.

Оси проверки: Domain (Endpoint, Credential, Access, Observation, Health,
Capability, Pool, Lease, Incident) · Infrastructure (Postgres, Redis, ASK,
Worker, Parser, Judges, Cache) · Interfaces (Bot, Mini App, Web, CLI) ·
Security (tenant isolation, SSRF, credentials, encryption, magic links,
initData, exports, worker API) · Reliability (idempotency, retries, DLQ, lease
recovery, worker/judge/Redis/Postgres outage) · Performance (10/100/1k/10k) ·
Protocols (все 7) · Lifecycle (все состояния) · Derived data (Health,
Capability, Scores, Metrics, VerifiedProjection, Pools, Incidents).

### 11.34 Приоритеты исправлений

- **P0 — до любого кода**: canonical Endpoint vs verified projection;
  identity-модель; worker vs application/domain ownership; #76 transport
  architecture (capability matrix); parser library boundary; MTProto как
  отдельный protocol capability; lifecycle state machine; observation →
  health/score pipeline; ProbeCacheKey v2 + tenant isolation; Postgres vs Redis.
- **P1 — до MVP**: failure taxonomy; probe profiles; policy snapshots; judge
  trust model; lease crash/recovery; tenant defense-in-depth; credential
  model/encryption/export; application layer boundary; verified projection;
  telegram compatibility model.
- **P2 — после vertical slice**: dynamic pool decision log UI; incident engine
  UI; advanced metrics; feed optimization; multi-region fleet; Gateway;
  standalone parser-svc (если потребность останется).

### 11.34.1 Приоритеты раунда 7 (External Tool boundary, §11.39)

- **P0 (добавка, до кода execution layer)**: P0.11 Worker/Checker boundary
  (4 уровня) · P0.12 ProbeTool contract · P0.13 ProbeExecution V1 wire
  contract · P0.14 ExecutionFailure vs ProxyFailure · P0.15 external binary
  security boundary (no argv credentials, no arbitrary exec, governor) ·
  P0.16 Tool Registry/Manifest · P0.17 Resource Governor · P0.18 control-plane/
  execution-plane split.
- **P1**: remote checker nodes; mTLS; multi-region runners; tool auto-discovery;
  tool version rollout; canary tool versions.

### 11.35 Формализация границ (раунд 5, 2026-08-26)

Внешний ревью (не знавший о shared probe-cache) подтвердил архитектуру и нашёл
20 уточнений. Все приняты. Ключевой структурный gap: **где живут health/lifecycle**
и формальность контрактов credential/task. От этого зависят cache, leases,
verified projection, selection, worker contract и DB schema — поэтому это
последний блок перед Stage 1.

**1. Health/lifecycle — на уровне Access, не Endpoint (главное исправление).**
`ProxyEndpoint` = network endpoint (host:port, scheme); `ProxyAccess` =
операционно проверяемая и выдааваемая идентичность (endpoint + credential).
Пример: на одном endpoint credential A → WORKING, credential B → AUTH_FAILURE,
credential C → WORKING. Семантика:
- **health_score, lifecycle (§11.6), quarantine — на ProxyAccess**;
- capability — частично на Endpoint (TCP-достижимость, scheme), частично на
  Access (auth, UDP, DNS mode, MTProto secret validity);
- endpoint-level состояние допускается ТОЛЬКО как derived summary (лучшая из
  access'ов), никогда не canonical;
- leases, verified projection, selection работают с AccessIdentity.

**2. ProbeCache: key/value контракт жёстко.** Key не содержит tenant_id.
Value — **только raw observations** (exit_ip, latency samples, headers,
dns observations, timestamps, judge/checker ids). Запрещено в value:
tenant_id, health/capability scores, lifecycle state, quarantine, selection
decisions, policy-derived anonymity tier. Нарушение разрушает модель
«shared raw + tenant interpretation». Дополнительно фиксируется:
**shared observation cache ≠ shared domain entity** — endpoint остаётся
tenant-owned (§11.22); глобального реестра endpoint'ов нет и не будет.

**3. Credential DTO — два типа, границы зафиксированы.**
- `ParsedCredentialPayload` — plaintext, internal-only, **никогда не
  сериализуется** в Redis/events/API/логи; живёт только внутри процесса между
  ParserDTO → CredentialNormalizer → CredentialEncryptor;
- `EncryptedCredential` / `CredentialReference` — serializable readonly DTO,
  безопасен для персистентности и контрактов (AuditTask несёт reference,
  не секрет).
ParserResponse V1 **не содержит** `password_encrypted` — парсер не имеет права
шифровать (уточняет §10.12 п.23; шифрование — после парсера, §11.15 п.3).

**4. AuditTask V1 — immutable execution snapshot.**
```
AuditTask {
  task_id, attempt_id, tenant_id,
  access_id,
  endpoint_snapshot   {scheme, host, port},
  credential_execution (SealedCredentialPayload — см. п.5;
                        в application/domain слоях используется
                        CredentialReference),
  policy_snapshot_id,
  probe_profile (+version),
  target_set          {judge_set_snapshot_version, tg_dc_set_version, targets}
}
```
Worker получает всё для исполнения сразу; worker НЕ лезет по `access_id` в
Postgres — иначе он снова зависит от доменной БД и перестаёт быть заменяемым.

**5. Worker credential delivery — sealed payload (вариант A).** Application
дешифрует credential и передаёт воркеру short-lived sealed execution payload
(шифруется отдельным runtime-ключом, TTL = job TTL). Явно запрещён вариант
«worker сам вызывает decrypt(KEK)»: encryption boundary не переезжает в worker.
Вариант B (capability-token + secrets boundary) зарезервирован на будущее,
если воркеры станут внешними.

**6. tenant_id в worker contract — metadata only.** Воркер никогда не делает
authorization по tenant_id: цепочка доверия — authenticated context (API/Bot/
MiniApp) → application создаёт AuditTask → worker использует tenant_id только
как метку для логов/DLQ routing. Особенно важно при DLQ/retry/replay: replay
идёт через application layer, который пересоздаёт task с валидным контекстом.

**7. PolicySnapshot разделяется.**
- `AuditPolicySnapshot` (в job): probe profile, lifecycle thresholds, judge set,
  targets, governors, retry policy — только то, что может изменить результат job;
- `WorkspacePolicy` (не snapshot'ится): retention, quotas, UI flags, export rules.
Snapshot ≠ копия всех настроек workspace.

**8. JudgeSetSnapshot — immutable versioning.**
`JudgeSetSnapshot{version, judges[JudgeDefinition], created_at}`;
ProbeCacheKey использует его version. Любое изменение судьи (URL, timeout,
headers, rate limit, capabilities) → новая версия snapshot'а → автоматическая
инвалидация старого кэша.

**9. Lifecycle transitions — capability-aware, по dimension-specific evidence.**
Правило «любой успех → ступень вверх» неверно (DEAD → light TCP OK → WORKING
недопустимо). Модель (уточнено §11.37 R6.3): evidence **не линейная лестница**
— bandwidth=100Mbps не «здоровее», чем TCP+TLS+HTTP full fetch; Telegram-чек
доказывает только TG connectivity. Вводятся типы evidence
(`LivenessEvidence, TransportEvidence, AnonymityEvidence, UdpEvidence,
DnsEvidence, TelegramEvidence, BandwidthEvidence`) с applicability:
`REQUIRED / OPTIONAL / NOT_APPLICABLE` — по типу Access. Pipeline:
`ProbeOutcome → Evidence[] → HealthEvaluation per dimension → Global/Target
Health → StateTransitionDecision`. Для MTProto HTTP/UDP пробы N/A и не мешают
WORKING при успешном handshake + TG connectivity.

**10. telegram_usable — freshness обязательна.** Поля модели:
`telegram_connectivity, telegram_usable, telegram_checked_at,
telegram_fresh_until, telegram_evidence_version`. `usable` выводится из
последнего результата И freshness policy: протухший флаг не выдаётся как true,
даже если последний чек был успешным (proxy может быть уже DEAD).

**11. Verified eligibility — capability-aware.** Предикат проекции — не просто
«health ≥ WORKING»: для MTProto HTTP-пробы N/A, но relay работает и TG
connectivity healthy → должен попасть в проекцию. Вводится
`VerifiedEligibilityPolicy` (по protocol × доступным evidence), а не порог
одного score.

**12. MTPROTO — application protocol, не transport.** Разделяются:
`ProxyProtocol` (идентификация), `TransportCapability` (HTTP/SOCKS-семантика —
что реализует #76 в php-async-kernel-client), `ApplicationCapability`
(MTProto = application protocol поверх TCP; TG connectivity — отдельная
capability pipeline, Stage 8). Запрещено трактовать MTPROTO как transport в
`ProxyTransport::connect()` — иначе #76 реализует его в библиотеке транспорта,
что архитектурно неправильно.

**13. TelegramDcSet — версионированная конфигурация.**
`TelegramDcSet{version, dc_id, addresses[], ports[], enabled}`; версия входит
в cache/evidence identity TG-проб. Смена списка DC инвалидирует старые
TG observations.

**14. SSRF — три разные политики вместо одного guard'а.**
Outbound-кейсы различаются направлением доверия:
- `ProxyEndpointConnectPolicy`: чекер → прокси (proxy host МОЖЕТ быть приватным
  IP — легитимный inventory);
- `JudgeConnectPolicy`: чекер → судья — строго allowlist (фиксированные судьи);
- `TargetFetchPolicy`: proxy → target — denylist private/metadata, marker-защита.
Единый SsrfGuard переиспользуется как примитив, но политики раздельные.

**15. Парсинг приватных IP/CIDR — semantic modes.** Архитектурный инвариант:
**parser validity ≠ network reachability ≠ SSRF eligibility.** `10.0.0.1:8080` —
валидная запись inventory (парсится, хранится, показывается), но запрещена как
outbound target без явного opt-in режима (trusted-admin, как в nettools).
Импорт CIDR расширяется в endpoints с тем же инвариантом.

**16. Фиды: глобальные определения, тенантные импорты.** Разделение:
- `SystemFeedDefinition` — global (monosans/TheSpeedX/ErcinDedeoglu, URL,
  расписание);
- `FeedSyncRun` — global (один скачивает фид один раз);
- `RawFeedEntry` — tenant import candidate (проекция записей фида в workspace
  при подписке);
- иначе один и тот же файл качается для каждого workspace — недопустимо.

**17. Source chain ownership.** `ProxySource` = logical source (feed/URL/paste,
tenant-owned) → `ImportBatch` = tenant-операция импорта (idempotency batch hash)
→ `RawFeedEntry/ParsedRecord` = конкретные записи (staging) → `ProxyEndpoint/
ProxyAccess` = результирующие доменные объекты. Понятия не пересекаются;
retention staging-записей — по WorkspacePolicy.

**18. Event ordering — транзакционная атомарность.** Жёсткое правило:
```
BEGIN
  Observation insert
  Health update
  Lifecycle transition
  Domain events append
  Integration outbox insert
COMMIT
-- only AFTER commit:
projections (VerifiedProxyProjection), notifications, webhooks
```
Иначе проекция увидит `AuditCompleted` до фиксации health-транзакции.

**19. proxy_events = event log + transactional outbox.** Одна таблица является
immutable domain event log И одновременно outbox: dispatch-метаданные
(`consumed_at`, `attempts`) — в служебных колонках dispatcher'а, не разрушают
append-only. Удаление dispatched events запрещено (это event log); архивирование
— через партиции.

### 11.36 Приоритеты раунда 5 (добавка к §11.34)

P0 перед Stage 1 (все закрыты пунктами §11.35):
P0.1 Access-level health/lifecycle (п.1) · P0.2 credential DTO contract (п.3) ·
P0.3 AuditTask execution snapshot (п.4) · P0.4 worker credential delivery (п.5) ·
P0.5 JudgeSetSnapshot/versioning (п.8) · P0.6 TelegramDcSet/versioning (п.13) ·
P0.7 SSRF policy split (п.14) · P0.8 verification eligibility policy (п.11) ·
P0.9 event transaction/ordering semantics (пп.18–19) · P0.10 feed global vs
tenant (пп.16–17).

Статус готовности: архитектура достаточна для старта Stage 0; формальность
для Stage 1 достигается этим разделом. Stage 0 разбит на задачи #87–94
(§7); чеклист из §11.35 (identity/access semantics, два credential-DTO,
AuditTask snapshot, sealed delivery, snapshot versioning, SSRF policies,
eligibility policy, event ordering, feed split) распределён по #87/#90/#92/#93.

### 11.37 Финальная полировка (раунд 6, 2026-08-26)

Повторная consistency-проверка: архитектурная модель не меняется — это патчи
контрактов R6.1–R6.8. Часть применена прямо в старых разделах (§5, §10.12
пп.6/20, §11.2, §11.6, §11.7, §11.9, §11.18, §11.21, §11.35 пп.4/9), здесь —
остальное и обязательный артефакт Stage 0.

**R6.1 Access-level lifecycle повсеместно.** Все старые формулировки
endpoint-level lifecycle удалены; канонический термин — `AccessState`
(переименование из EndpointState намеренное, чтобы не провоцировать ошибки).
Каноническое описание: ProxyEndpoint = network identity (scheme/host/port);
ProxyAccess = endpoint + credential + health + lifecycle + quarantine +
freshness + selection eligibility + leaseability.

**R6.2 ProbeCacheKey V3 + fingerprints.** Ключ — §11.7 (добавлены
checker_node_id, egress_identity, telegram_dc_set_version,
probe_semantics_version; checker_region — metadata). Дополнительно:
- `CredentialFingerprint = HMAC-SHA256(system-secret, canonical credential
  payload)` — глобально детерминированный, tenant_id не входит: одинаковые
  user/pass у разных workspace дают один fingerprint (иначе кэш перестаёт быть
  shared); разные пароли — разные. system-secret — отдельный ключ из конфига
  (не KEK credentials);
- **EndpointIdentity canonicalization**: lowercase hostname, IDNA, IPv6
  normalization (RFC 5952), default-port handling, scheme normalization,
  trailing dot removal, canonical credential representation. Без этого одинаковые
  прокси дают разные cache keys.

**R6.3 Evidence — dimension-specific, не лестница.** Убрана линейная модель
`light < deep < telegram < bandwidth`. Типы evidence:
`LivenessEvidence, TransportEvidence, AnonymityEvidence, UdpEvidence,
DnsEvidence, TelegramEvidence, BandwidthEvidence`; applicability
REQUIRED/OPTIONAL/NOT_APPLICABLE по типу Access; pipeline в §11.35 п.9.
ProbeProfile (§11.17) определяет, какие evidence-типы собирает профиль.

**R6.4 Cache value = safe raw evidence (allowlist).** Запрещено хранить даже
если получено через proxy: response body, Authorization, Proxy-Authorization,
Set-Cookie, session identifiers, request headers с секретами. Инвариант
liveness: **full body MUST be fetched, full body MUST NOT be cached**
(full-fetch ≠ full-body storage). В кэше достаточно: status, content_length,
body_hash, bytes_received, timings, allowlisted observation headers, exit_ip,
dns observations, marker results.

**R6.5 CredentialReference vs SealedCredentialPayload.** Domain/application
слои оперируют `CredentialReference`; worker contract несёт только
`SealedCredentialPayload` (unseal runtime-ключом с TTL job'а). Worker не имеет
доступа к PROXY_ENC_KEY / DEK / CredentialEncryptor.

**R6.6 Formula/policy versions в derived данных.** Обязательно хранить рядом
с производными значениями: `verification_policy_version` (в verified projection),
`health_formula_version`, `capability_formula_version`,
`anonymity_classifier_version`. Без них невозможно ответить «почему verified
3 дня назад, а сейчас нет».

**R6.7 proxy_events immutability — точная семантика.** Immutable: event payload,
event identity, occurred_at. Operational metadata (НЕ часть события):
`dispatch_status, attempt_count, last_attempt_at, consumed_at` — меняются
dispatcher'ом. Разработчик не должен считать всю строку immutable.

**R6.8 Feed entry identity + tenant import идемпотентность.**
`GlobalFeedEntryIdentity = feed_id + normalized_record_hash`;
tenant import key = `tenant_id + global_feed_entry_id + import_policy_version`.
Иначе hourly sync плодит дубликаты в staging каждого workspace.

**Дополнительно зафиксировано:**
- **Hostname-based identities и DNS**: для proxy по имени (`proxy.example.com`)
  execution evidence дополняется `proxy_resolution {resolved_addresses[],
  resolution_timestamp, resolver_mode}`; cache policy запрещает долгое
  переиспользование hostname-based observations (короткий TTL) — DNS rotation
  делает старую пробу нерелевантной.
- **Redirect policy**: `ProxyEndpointConnectPolicy` запрещает redirect/chaining-
  обход: для judge/target HTTP-проб `max_redirects = 0` либо строго
  allowlisted targets (особенно marker probes) — иначе легитимный прокси может
  стать трамплином во внутреннюю сеть.
- **telegram evidence_scope**: `TelegramCompatibility` дополняется
  `failed_dc_count, checked_dc_set_version, classification_reason`
  («usable because 3/5 DC reachable» — объяснимость для UI).

#### Invariant Matrix — обязательный Stage-0 artifact

Неизменяемые архитектурные инварианты (тестируются arch-тестами, нарушение =
баг дизайна, не фича):

| ID | Инвариант |
|---|---|
| INV-001 | health/lifecycle/quarantine принадлежат ProxyAccess |
| INV-002 | Endpoint никогда не содержит canonical health |
| INV-003 | worker никогда не читает Postgres |
| INV-004 | worker никогда не владеет KEK/DEK |
| INV-005 | shared cache никогда не содержит tenant interpretation |
| INV-006 | tenant_id никогда не приходит от клиента как authoritative |
| INV-007 | parser никогда не шифрует credentials |
| INV-008 | MTPROTO — не transport |
| INV-009 | Redis никогда не становится domain truth |
| INV-010 | проекция никогда не мутирует canonical domain |
| INV-011 | external tool никогда не получает domain entities |
| INV-012 | external tool никогда не принимает arbitrary executable/argv |
| INV-013 | credentials никогда не передаются через process argv |
| INV-014 | tool failure не является автоматически ProxyFailure |
| INV-015 | checker infrastructure failure не изменяет proxy health |
| INV-016 | worker execution contract версионируется независимо (wire-level) |
| INV-017 | tool version/semantics участвует в reproducibility/cache semantics |
| INV-018 | domain/application не зависят от конкретного binary/CLI |
| INV-019 | external execution ограничено resource/security governor |
| INV-020 | control plane и execution plane разделены |

Все 20 — обязательные arch-тесты Stage 0 (задача #94).

Итог раунда 6: shared cache остаётся — он корректен при трёх условиях:
(1) tenant не входит в domain identity ключа; (2) tenant-derived данные не
попадают в value; (3) raw evidence не содержит credentials/tenant-sensitive
metadata. После этого раунда архитектурная модель фиксируется.

### 11.38 Приоритеты раунда 6 и фиксация модели

Все 8 патчей R6.1–R6.8 применены (§11.37 + правки в §§5, 10.12, 11.2, 11.6,
11.7, 11.9, 11.18, 11.21, 11.35, задача #84). Архитектурная модель
**заморожена**: дальнейшие изменения — только через ADR.

### 11.39 External Tool / Binary Execution Architecture (раунд 7, 2026-08-26)

Ревью исполнения внешних инструментов. Главный принцип подтверждён и усилен:
**PHP — orchestration/domain/application layer; тяжёлые/опасные/
специализированные бинарники — отдельные контейнеры; интеграция только через
стабильный API / worker protocol или контролируемый CLI-wrapper.** Главное
изменение — появляется чёткая **четвёртая граница**:

```
Domain/Application  →  Audit Worker  →  Probe Runner  →  External Binary
```

PHP остаётся хозяином системы; бинарники — заменяемые вычислительные
инструменты. Итоговая схема:

```
Telegram/Web/MiniApp/CLI
        │
        ▼
PHP Platform (Domain · Application · Policies · Scheduler · Selection ·
              Import/Export · Health/Lifecycle · Projection)
        │  AuditTask V1
        ▼
Audit Worker (Queue · Planner · Executor · Result Normalizer · Reporter ·
              Resource Governor)
        │  Probe Execution V1
        ▼
HTTP Runner      SOCKS Runner      MTProto Runner     (ProbeTool adapters)
   │                 │                  │
 curl/etc         binary           mtproto binary       (External Tools)
   └─────────────────┼──────────────────┘
                     ▼ raw result
              AuditResult V1
                     ▼
        PHP Evidence Pipeline → Health | Capability | Telegram
                     ▼
                AccessState → Selection | Lease | Verified
```

**1. Четыре уровня исполнения.** Scheduler решает WHEN; Audit Worker — какую
задачу выполнить, какие probe запустить, как собрать результаты, обрабатывать
timeout/crash; Probe Runner — как вызвать инструмент; External Tool — конкретную
сетевую операцию. Worker Gateway не становится новым domain layer.

**2. Единый Probe Execution Protocol — не API на каждый бинарник.** Никакого
зоопарка `curl-api`, `mtproto-api`, `dns-api`. Один протокол, за ним runners:
`HTTP probe runner | SOCKS runner | MTProto runner | ...`. Внутри runner может
использовать curl / Go binary / Rust utility / библиотеку — PHP не знает.

**3. Transport — не только HTTP.** `ProbeRunnerTransport`: **UnixSocket**
(локальный контейнер — лучший IPC) | **HTTPS+mTLS** (remote checker node).
Одна архитектура покрывает локальный checker, отдельный Docker, отдельную
машину, regional fleet.

**4. Control plane ≠ execution plane.**
```
Control:    GET /health  GET /ready  GET /metrics  GET /capabilities
Execution:  POST /v1/executions  GET /v1/executions/{id}  POST .../cancel
```
Смешение запрещено: cancel job'а — это не health-check.

**5. External tool никогда не видит доменных сущностей.** Запрещено передавать
binary ProxyAccess/AuditJob/Tenant/Health. Вход — минимальный ProbeInput:
```json
{"protocol":"socks5",
 "endpoint":{"host":"...","port":1080},
 "credential":{"username":"...","password":"..."},
 "probe":{"type":"http_liveness","target":"..."}}
```
Выход: `{"status":"ok","timings":{...},"observations":[...]}`. Бинарник
заменяется без изменения доменной модели (INV-011).

**6. Минимальное время жизни секрета.** Unseal в worker → ephemeral memory →
ExecutionContext → ProbeRunner → minimal ProbeInput. Binary не получает весь
AuditTask — только то, что нужно конкретной пробе.

**7. Credentials никогда через argv (INV-013).** Запрещено
`checker --proxy user:password@host:port`: секрет утекает в process list,
/proc, audit, debug, crash dump, tracing, shell history, container runtime
metadata. Только stdin/pipe либо Unix FD / ephemeral file с жёсткими
permissions (если конкретный binary требует файл); zeroization после выполнения.

**8. ProbeTool contract.**
```php
interface ProbeTool {
    public function capabilities(): ToolCapabilities;
    public function execute(ProbeExecutionContext $context): ProbeToolResult;
}
```
Реализации `CurlProbeTool / SocksProbeTool / DnsProbeTool / MtprotoProbeTool /
TcpProbeTool`; внутри каждой — CLI/binary/библиотека как implementation detail.
Домен видит ProbeTool, а не proc_open/shell_exec/curl (INV-018).

**9. CLI-wrapper — security boundary.** Никогда `shell_exec($userInput)`.
Только: HTTP → validated DTO → Command Builder → argv[] → execve
(`proc_open([$binary, $arg1, ...], ...)`, массивная форма, без shell).

**10. Tool Registry + запрет arbitrary execution (INV-012).**
`POST /execute {"binary": "/bin/bash", ...}` категорически запрещён. Только
`tool_id` из allowlist; Registry резолвит `tool_id → fixed executable`.
Registry публикует на каждый tool: name, version, protocols, capabilities,
required privileges, timeout/resource limits, input/output schema versions.
При старте worker получает `GET /capabilities` и проверяет совместимость —
никаких `if ($version === '2.4')`.

**11. Tool Manifest.**
```yaml
name: mtproto-checker
version: 2.4.1
api_version: 1
capabilities: [mtproto.handshake, telegram.dc_connectivity]
limits: {max_execution_time: 30s, max_output_bytes: 1mb}
security: {network: outbound-only, filesystem: readonly, privileges: none}
```

**12. tool_semantics_version — в evidence identity.** ProbeCacheKey V3
дополняется семантически значимой версией execution tool (`tool_name`,
`tool_version`, `tool_protocol_version` → сводный `tool_semantics_version`;
НЕ git SHA/build metadata — иначе cache fragmentation). checker v1 и v2 могут
давать разные результаты (уточняет R6.2, INV-017).

**13. Tool failure — отдельные коды.** TOOL_TIMEOUT, TOOL_CRASH,
TOOL_PROTOCOL_ERROR, TOOL_OOM, TOOL_EXIT_FAILURE, TOOL_OUTPUT_INVALID,
TOOL_UNAVAILABLE (класс CHECKER, §11.16). TOOL_TIMEOUT ≠ proxy failure:
это может быть отказ checker-инфраструктуры (INV-014/015).

**14. ProbeFailure vs InfrastructureFailure.** Audit → HTTP probe → checker
crashed — это НЕ observation прокси. `proxy_observations` не содержит TOOL_*
как proxy observation; только `success → Observation | execution failure →
ExecutionFailure`.

**15. Resource Governor (INV-019).** Без лимитов 100 jobs × 100 processes =
DoS собственного checker node. Worker enforce: max_concurrent_probes,
max_processes, max_memory, max_cpu, max_execution_time, max_output_bytes,
max_stdin_bytes, max_file_descriptors. Контейнеры дополнительно: memory limit,
cpu quota, pids limit, read-only filesystem, no-new-privileges, cap_drop ALL,
network policy. Часть security baseline Stage 0 (#91).

**16. Capability-specific контейнеры.** Не «checker:latest с 40 программами»:
`checker-http / checker-socks / checker-dns`, MTProto отдельно (специфические
зависимости). Меньше attack surface, проще CVE scan, resource limits,
compatibility, rollback.

**17. Control plane не выставляется наружу.** Internet ✗ → Worker Control API.
Только private docker network; `/health /ready` — internal. Remote worker —
mTLS + worker authentication + short-lived execution authorization.

**18. Worker не PHP-only — контракт сейчас, не в будущем (INV-016).**
`AuditTask V1 / AuditResult V1 / ProbeExecution V1` — wire-level контракты с
первого дня: php-worker сегодня, go-worker/rust-worker завтра выполняют тот же
AuditTask (особенно важно для MTProto и высококонкурентных проб).

**19. PHP остаётся владельцем доменных решений.** HealthEvaluator,
LifecyclePolicy, CapabilityEvaluator, VerifiedEligibility, Selection, Lease,
Incident — НИКОГДА не переезжают в worker/runners. Разделение: worker/runners =
execution; PHP = Evidence → Health/Capability → Lifecycle → Projection.

**20. CLI — adapter, не интеграционный контракт.** Правило: «CLI является
implementation detail execution layer». Домен вызывает ProbeTool contract;
адаптер — CLI или HTTP-runner — неважно, реализации одинаковы для домена.

Итог раунда 7: модель из §11.38 уточняется этим разделом (изменение через ADR,
что соответствует правилу заморозки). Stage 0 разбит на задачи разумного
размера #87–94; приоритеты P0.11–P0.18 — §11.34.1.
