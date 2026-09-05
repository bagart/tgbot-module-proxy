# T87 — Identity model + protocol/capability matrix

Plan ref: task #87; plan §§11.2–11.5, 11.35 пп.1–3. Depends on: T00.

## Goal

Canonical identity layer: identical proxy endpoint/credential always produces
identical identity strings regardless of input spelling. Foundation for cache,
dedup, and audit addressing (INV-005/INV-017 rely on it).

## Create under `src/Domain/Identity/`

- `ProxyProtocol` enum (backed string): `Http`, `Https`, `Socks4`, `Socks4a`,
  `Socks5`, `Socks5h`, `Mtproto`. MTPROTO is an application protocol, not a
  transport (plan §11.35 п.8 / INV-008).
- `TransportKind` enum: `TcpDirect`, `TcpViaProxy`, `UdpAssociate`, `DnsModes`.
- `ProtocolCapabilityMatrix`: for each protocol — supported transport kinds and
  application capabilities (http targets, https targets via CONNECT, udp, dns
  resolution side, telegram connectivity). Pure data + query methods.
- `EndpointCanonicalizer`: canonicalizes host/port/scheme into stable form —
  IDNA (unicode host → punycode), IPv6 → RFC 5952 lowercase compressed,
  scheme-aware default ports dropped (`http:80`, `socks5:1080`),
  trailing dot removed, host lowercased.
- `EndpointIdentity` readonly DTO: canonical host, port, protocol;
  `toString()`, `equals()`; JSON round-trip.
- `CredentialFingerprint`: HMAC-SHA256 over normalized credentials with injected
  key (constructor-injected binary key, never a hardcoded secret); hex output;
  never exposes source credentials (masked by default rule).
- `AccessIdentity` composite DTO: endpoint identity + credential fingerprint +
  derived stable key (sha256 of parts).

## Tests (`tests/Unit/Domain/Identity/`)

- Canonicalization vectors: unicode IDN, uppercase/trailing-dot hosts,
  IPv6 forms (`::1`, full, mixed), default-port stripping per protocol,
  non-default ports preserved.
- Fingerprint stability + key sensitivity; different credential order/user-pass
  normalization documented in plan.
- Matrix: MTPROTO has no SOCKS transport capability; socks5h resolves DNS on
  proxy side; etc. (assert plan §11.4 rows).
- JSON round-trip + `fromJsonV1` version rejection.

## Acceptance

`composer test` green; no dead public methods.
