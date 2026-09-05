# Stage 2 — Parser (plan tasks #16–20)

Source: `../plan.md` §§10.12 пп.2–3, 11.15 пп.2–3.

Scope: `ProxyOperations\Domain\Parsing` library — one grammar for list import:
host:port, scheme://, user:pass@host:port, host:port:user:pass, host port,
CIDR expansion, `mtproto://`, `tg://proxy` (hex/dd/+r/FakeTLS secrets).
VPN formats rejected with clear error. `ImportProxiesCommand` application
service shared by bot/API/CLI/feeds. Parser outputs NormalizedEndpoint → dedup;
never encrypts credentials (INV-007). Unit tests per grammar rule + negative
cases.

Exit: parser library + command + tests green.
