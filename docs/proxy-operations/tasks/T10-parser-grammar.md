# T10 — Parser grammar library: Domain\Parsing

Plan ref: plan §§10.12 п.3, 11.15 п.2–3, 11.28, 11.35 п.3/15; INV-007.
Depends on: T03 (ProxyEndpoint model — parser reuses EndpointCanonicalizer), T04 (CredentialKind enum).

## Goal

Pure-PHP grammar library that parses proxy list text into structured entries.
Single responsibility: text → `ParsedEntry[]` + `ParseError[]`. No Eloquent,
no encryption (INV-007), no tenant awareness. The library is consumed by the
application-service bridge (T12).

## Deliverables (file-by-file)

### Namespace: `BAGArt\ProxyOperations\Domain\Parsing`

All classes are `final readonly` with constructor promotion (global DTO rules).

#### DTOs

- `src/Domain/Parsing/ParsedEntry.php`
  ```php
  final readonly class ParsedEntry implements JsonSerializable
  {
      public const int SCHEMA_VERSION = 1;

      public function __construct(
          public readonly string $scheme,       // e.g. "socks5", "mtproto"
          public readonly string $host,
          public readonly int $port,
          public readonly ?string $username,    // null for credential-free / MTProto
          public readonly ?string $secret,      // plaintext; never serialized (INV-007)
          public readonly CredentialKind $credentialKind, // from Domain\Identity
          public readonly int $sourceLine,      // 1-based line number in input
          public readonly ?string $originalHost, // as typed, before canonicalization
      ) {}
  }
  ```
  `jsonSerialize()` omits `secret` (plaintext must never reach serialized output).
  `fromJsonV1()` reconstructs from JSON (secret will be null — by design).

- `src/Domain/Parsing/ParseError.php`
  ```php
  final readonly class ParseError implements JsonSerializable
  {
      public const int SCHEMA_VERSION = 1;

      public function __construct(
          public readonly int $line,
          public readonly string $rawLine,      // trimmed, first 200 chars
          public readonly ParseErrorCode $code,
          public readonly ?string $detail,      // human-readable (domain codes, not i18n)
      ) {}
  }
  ```

- `src/Domain/Parsing/ParseErrorCode.php`
  ```php
  enum ParseErrorCode: string
  {
      case EmptyLine = 'empty_line';
      case CommentLine = 'comment_line';
      case NonVpnRejected = 'non_vpn_rejected';       // vless/vmess/trojan/ss/wireguard/openvpn
      case UnsupportedScheme = 'unsupported_scheme';
      case InvalidHost = 'invalid_host';
      case InvalidPort = 'invalid_port';
      case InvalidPortRange = 'invalid_port_range';
      case MissingPort = 'missing_port';
      case InvalidFormat = 'invalid_format';
      case InvalidMtprotoSecret = 'invalid_mtproto_secret';
      case CidrExpansionFailed = 'cidr_expansion_failed';
  }
  ```

- `src/Domain/Parsing/ParseResult.php`
  ```php
  final readonly class ParseResult implements JsonSerializable
  {
      public const int SCHEMA_VERSION = 1;

      public function __construct(
          /** @var ParsedEntry[] */
          public readonly array $entries,
          /** @var ParseError[] */
          public readonly array $errors,
          public readonly int $totalLines,
          public readonly int $parsedCount,
          public readonly int $errorCount,
      ) {}
  }
  ```

#### Grammar

- `src/Domain/Parsing/ProxyListParser.php`
  ```php
  final class ProxyListParser
  {
      public function parse(string $text): ParseResult { ... }
  }
  ```

  **Grammar rules** (plan §10.12 п.3):
  1. Lines are split on `\n`; empty lines and lines starting with `#` or `//` are skipped.
  2. Each line is trimmed; trailing whitespace stripped.
  3. **Format detection** (priority order):
     - `mtproto://SECRET@HOST:PORT` or `tg://proxy?server=HOST&port=PORT&secret=SECRET`
       → MTProto (see MTProto section below)
     - `SCHEME://[USER:PASS@]HOST:PORT` where SCHEME ∈ {http,https,socks4,socks4a,socks5,socks5h}
       → recognized scheme
     - `USER:PASS@HOST:PORT` → defaults to socks5
     - `HOST:PORT:USER:PASS` → defaults to socks5 (only if HOST is valid and PORT ∈ 1–65535)
     - `HOST PORT` (space-separated) → defaults to socks5
     - `HOST:PORT` → defaults to socks5
  4. **Non-VPN guard** (plan §1, INV-007 scope): lines starting with `vless://`, `vmess://`,
     `trojan://`, `ss://`, `wireguard://`, `openvpn://` (case-insensitive) produce
     `ParseError` with code `NonVpnRejected` — never silently dropped.
  5. **IPv6**: brackets required without scheme (`[::1]:8080`); with scheme
     `socks5://[::1]:8080` works. Bare `::1` without brackets rejected with detail.
  6. **Default ports** (from `ProxyProtocol::defaultPort()`): http→80, https/mtproto→443,
     socks*→1080. Missing port → default for detected scheme.
  7. **CIDR expansion**: `HOST/CIDR` (e.g. `10.0.0.0/24`) — expand into individual
     endpoints with same port/credentials. Hard limit: 1024 IPs per CIDR entry
     (configurable via constructor param `$cidrMaxExpansion`); exceeding produces
     `CidrExpansionFailed` error with detail showing the limit.
  8. **Ambiguity resolution** (plan §10.12 п.3): scheme prefix takes priority;
     `a:b:c:d` = host:port:user:pass when `a` is a valid host and `b` ∈ 1–65535.
     Bare `a:b` where neither is a valid host:port → `InvalidFormat`.

  **MTProto parsing** (plan §§10.12 п.22, 11.35 п.12):
  - `mtproto://SECRET@HOST:PORT` — secret is hex-encoded; validate hex + length
    (16-byte = plain, 17+ with `ee` prefix = FakeTLS, `dd` = padded, `+r` = restricted).
  - `tg://proxy?server=HOST&port=PORT&secret=SECRET` — parse query params.
  - `https://t.me/proxy?server=HOST&port=PORT&secret=SECRET` — same as tg://proxy.
  - MTProto entries produce `ParsedEntry` with `credentialKind = CredentialKind::MtprotoSecret`,
    `username = null`, `secret` = the raw secret string (hex).
  - Invalid MTProto secret (non-hex, wrong length) → `InvalidMtprotoSecret` error.

  **CredentialKind mapping** (plan §11.3):
  - HTTP/HTTPS with user:pass → `CredentialKind::BasicAuth`
  - SOCKS* with user:pass → `CredentialKind::SocksAuth`
  - MTProto → `CredentialKind::MtprotoSecret`
  - No credentials → `CredentialKind` still set per protocol (credential-free
    accesses are valid — plan §11.2); T12 handles the "no credential" path.

  **Parser does NOT call EndpointCanonicalizer** — it returns raw host/port/scheme.
  Canonicalization happens in T12 (application service) where TenantContext is
  available for uniqueness checks.

## Conventions

- Pure PHP, no Laravel imports in `Domain\Parsing`. Dependencies:
  `BAGArt\ProxyOperations\Domain\Identity\ProxyProtocol` (scheme enum) and
  `BAGArt\ProxyOperations\Domain\Identity\CredentialKind` (credential kind enum).
- DTOs follow `final readonly` + `SCHEMA_VERSION` + `fromJsonV1()` pattern.
- `ParsedEntry::secret` is plaintext by construction (internal-only, plan §11.35 п.3);
  `jsonSerialize()` must exclude it. Add an explicit check/annotation.
- LF line endings; English; `declare(strict_types=1)`.

## Tests

`tests/Unit/Domain/Parsing/ProxyListParserTest.php` (pure, no DB):

**Acceptance vectors** (plan §10.12 п.3):
- `socks5://user:pass@1.2.3.4:1080` → 1 entry, scheme socks5, CredentialKind::SocksAuth
- `http://proxy.example.com:8080` → 1 entry, scheme http, credential-free
- `1.2.3.4:1080` → 1 entry, scheme socks5 (default)
- `user:pass@1.2.3.4:1080` → 1 entry, CredentialKind::SocksAuth
- `1.2.3.4:1080:user:pass` → 1 entry, CredentialKind::SocksAuth
- `1.2.3.4 1080` → 1 entry (space-separated)
- `mtproto://abc123@1.2.3.4:443` → 1 MTProto entry
- `tg://proxy?server=1.2.3.4&port=443&secret=abc123` → 1 MTProto entry
- `https://t.me/proxy?server=1.2.3.4&port=443&secret=abc123` → 1 MTProto entry
- `10.0.0.0/30` → 4 entries (CIDR expansion)
- `[::1]:8080` → 1 entry, IPv6 host
- `socks5://[2001:db8::1]:1080` → 1 entry, IPv6 with scheme
- Comment lines (`# ...`, `// ...`) → skipped
- Empty lines → skipped

**Rejection vectors**:
- `vless://user:pass@host:port` → NonVpnRejected error
- `vmess://...` → NonVpnRejected
- `trojan://...` → NonVpnRejected
- `ss://...` → NonVpnRejected
- `wireguard://...` → NonVpnRejected
- `openvpn://...` → NonVpnRejected
- `socks5://host:99999` → InvalidPortRange
- `socks5://host:0` → InvalidPortRange
- `socks5://:1080` → InvalidHost
- `://host:1080` → InvalidFormat
- `10.0.0.0/16` exceeding cidrMaxExpansion → CidrExpansionFailed
- `mtproto://xyz@host:443` (non-hex secret) → InvalidMtprotoSecret
- Bare `::1:8080` without brackets → InvalidFormat

**ParseResult properties**: totalLines, parsedCount, errorCount consistent;
entries + errors lengths match counts.

**Secret never serialized**: verify `ParsedEntry::jsonSerialize()` output
does not contain `secret` key.

## Verify

```
timeout 120 ../../../vendor/bin/pest --testsuite Unit
timeout 120 ../../../vendor/bin/pest
```

## Out-of-scope

- Eloquent persistence, tenant scoping, dedup (T12).
- RawFeedEntry model (T11).
- EndpointCanonicalizer call (happens in T12).
- EncryptedField / CredentialEncryptor (T09, called by T12).
