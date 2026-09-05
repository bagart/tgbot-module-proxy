# Stage 3 — ASK transport (plan tasks #76, #81)

Source: `../plan.md` §§11.4–11.5, 11.30, 11.39, task #76 (`[ASK-TRANSPORT]`),
#81 (`[WORKER-CONTRACT]`).

Scope: proxy-aware transport over `bagart/php-async-kernel-client`: HTTP CONNECT,
SOCKS4/4a/5/5h + capability tests; UDP ASSOCIATE and DNS modes. Lazy
connections, no external I/O in constructors (platform rule). Credentials
never in argv/logs (INV-013). Resource governance. ASK daemon/tickable
integration. Worker control/execution plane routes. Feature tests with local
mock SOCKS/HTTP proxies (docker or in-process socket stubs).

Exit: transports + capability probes + resource governor + worker wiring + tests green.

Refined into: T15 (ProxyConfig DTOs), T16 (Transport Adapters), T17 (UDP/DNS
Modes), T18 (Worker Wiring).
