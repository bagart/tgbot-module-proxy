# T15 — ProxyConfig DTOs and Transport Capability Validation

Plan ref: plan §11.39 пп.3,5–6,8, §11.4–11.5, task #76 (`[ASK-TRANSPORT]`).
Depends on: T90 (wire contracts — `AuditTaskV1`, `CredentialReference`), T91
(`Tool` contracts — `ToolCapabilities`, `ProbeExecutionContext`,
`CredentialChannel`, `ProbeRunnerTransport`).

## Goal

Define the proxy configuration DTOs that bridge wire-level `AuditTaskV1` into
transport-level `ProbeExecutionContext`. These are value objects: they carry the
connection parameters for each protocol family without leaking domain entities
(INV-011). Each transport validates protocol-specific compatibility against the
`ProtocolCapabilityMatrix` before establishing a connection.

This task is pure DTOs + validation logic — no network I/O.

## Deliverables (file-by-file)

### DTOs — `src/Transport/`

- **`ProxyConfig.php`**
  ```php
  namespace BAGArt\ProxyOperations\Transport;

  final readonly class ProxyConfig implements JsonSerializable
  {
      public const int SCHEMA_VERSION = 1;

      public function __construct(
          public readonly ProxyProtocol $scheme,
          public readonly string $host,
          public readonly int $port,
          public readonly ?ProxyCredentialRef $credential,
          public readonly TlsOptions $tls,
          public readonly TransportOptions $transportOptions,
      ) { /* validation: port range, non-empty host */ }
  }
  ```

- **`ProxyCredentialRef.php`** — immutable, no-secret value object (secrets
  never cross this boundary; they live in `CredentialChannel`):
  ```php
  final readonly class ProxyCredentialRef implements JsonSerializable
  {
      public const int SCHEMA_VERSION = 1;

      public function __construct(
          public readonly ?string $username,
          public readonly CredentialChannel $channel,
      ) {}
  }
  ```

- **`TlsOptions.php`** — TLS configuration for the proxy connection:
  ```php
  final readonly class TlsOptions implements JsonSerializable
  {
      public const int SCHEMA_VERSION = 1;

      public function __construct(
          public readonly bool $verifyPeer = true,
          public readonly bool $allowSelfSigned = false,
          public readonly ?string $caBundlePath = null,
      ) {}
  }
  ```

- **`TransportOptions.php`** — abstract base for protocol-specific options;
  sealed hierarchy:
  ```php
  abstract class TransportOptions { /* empty base */ }
  ```

- **`SocksOptions.php`** — SOCKS-specific transport options:
  ```php
  final readonly class SocksOptions extends TransportOptions implements JsonSerializable
  {
      public const int SCHEMA_VERSION = 1;

      public function __construct(
          public readonly bool $enableUdpAssociate = false,
          public readonly SocksDnsMode $dnsMode = SocksDnsMode::Local,
      ) {}
  }
  ```

- **`SocksDnsMode.php`** — enum for DNS resolution semantics within SOCKS:
  ```php
  enum SocksDnsMode: string
  {
      case Local = 'local';
      case Remote = 'remote';     // SOCKS5 remote DNS
      case ProxyDns = 'proxy_dns'; // SOCKS5h: DNS resolved by proxy
  }
  ```

- **`HttpConnectOptions.php`** — HTTP CONNECT tunnel options:
  ```php
  final readonly class HttpConnectOptions extends TransportOptions implements JsonSerializable
  {
      public const int SCHEMA_VERSION = 1;

      public function __construct(
          public readonly bool $tunnel = true,
      ) {}
  }
  ```

- **`MtprotoOptions.php`** — MTProto-specific transport options (stub for now;
  Stage 8 fills in the handshake config):
  ```php
  final readonly class MtprotoOptions extends TransportOptions implements JsonSerializable
  {
      public const int SCHEMA_VERSION = 1;

      public function __construct() {}
  }
  ```

### Validation — `src/Transport/`

- **`ProxyConfigValidator.php`**
  ```php
  namespace BAGArt\ProxyOperations\Transport;

  use BAGArt\ProxyOperations\Domain\Identity\ProxyProtocol;

  final readonly class ProxyConfigValidator
  {
      private const array REQUIRED_OPTIONS_CLASS = [
          ProxyProtocol::Socks5 => SocksOptions::class,
          ProxyProtocol::Socks5h => SocksOptions::class,
          ProxyProtocol::Socks4 => HttpConnectOptions::class,
          ProxyProtocol::Socks4a => HttpConnectOptions::class,
          ProxyProtocol::Http => HttpConnectOptions::class,
          ProxyProtocol::Https => HttpConnectOptions::class,
          ProxyProtocol::Mtproto => MtprotoOptions::class,
      ];

      public function validate(ProxyConfig $config): void
      {
          // 1. Assert transportOptions is the expected class for the scheme.
          // 2. Assert CredentialChannel type matches protocol expectations
          //    (StdinChannel for SOCKS4/5, FileDescriptorChannel accepted everywhere).
          // 3. Assert SocksOptions.dnsMode is compatible with scheme
          //    (ProxyDns only for Socks5h; Remote only for Socks5/Socks5h).
          // 4. Assert enableUdpAssociate only for Socks5/Socks5h.
          // 5. TlsOptions.verifyPeer must be true in production (configurable
          //    via AuditPolicySnapshot.trustedSelfSigned for dev only).
          // Throws InvalidArgumentException with codes for each violation.
      }
  }
  ```

### Factory — `src/Transport/`

- **`ProxyConfigFactory.php`**
  ```php
  namespace BAGArt\ProxyOperations\Transport;

  use BAGArt\ProxyOperations\Wire\AuditTaskV1;
  use BAGArt\ProxyOperations\Wire\ProbeExecutionSpecV1;

  final readonly class ProxyConfigFactory
  {
      public function __construct(
          private readonly ProxyConfigValidator $validator,
      ) {}

      /**
       * Build ProxyConfig from a wire AuditTaskV1 for a specific probe.
       * Extracts host/port/scheme from AuditTaskV1.accessRef (via
       * AccessIdentity → EndpointIdentity → ProxyProtocol).
       * Builds CredentialChannel from sealed credential payload.
       * Selects TransportOptions from AuditPolicySnapshot or defaults.
       */
      public function fromAuditTask(
          AuditTaskV1 $task,
          ProbeExecutionSpecV1 $probe,
      ): ProxyConfig { /* ... */ }

      /**
       * Build ProxyConfig for a direct TCP connection (e.g., judge fetch).
       * No proxy, no credential.
       */
      public function direct(string $host, int $port): ProxyConfig { /* ... */ }
  }
  ```

### ProbeContext Builder — `src/Transport/`

- **`ProbeContextBuilder.php`**
  ```php
  namespace BAGArt\ProxyOperations\Transport;

  use BAGArt\ProxyOperations\Tool\ProbeExecutionContext;
  use BAGArt\ProxyOperations\Wire\AuditTaskV1;
  use BAGArt\ProxyOperations\Wire\ProbeExecutionSpecV1;

  /**
   * Converts AuditTaskV1 + ProbeExecutionSpecV1 → ProbeExecutionContext.
   * This is the bridge from wire contracts to Tool-layer execution input.
   * Domain entities (AccessIdentity, TenantId) are NOT propagated — only
   * host, port, credential channel, probe spec, timeout and output limit.
   */
  final readonly class ProbeContextBuilder
  {
      public function fromAuditTask(
          AuditTaskV1 $task,
          ProbeExecutionSpecV1 $probe,
      ): ProbeExecutionContext { /* ... */ }
  }
  ```

## Conventions

- All DTOs implement `JsonSerializable` + `SCHEMA_VERSION` + `fromJson()` +
  private `fromJsonV1()` per module convention (reference: `DeadLetterEntry`).
- `ProxyConfig` is the transport-layer value object; `ProbeExecutionContext`
  is the tool-layer input. They are distinct: `ProxyConfig` is used by
  transport adapters (curl/Go binary/PHP socket); `ProbeExecutionContext`
  is used by `ProbeTool` implementations.
- Secrets never appear in `ProxyConfig` — the `ProxyCredentialRef` carries only
  a channel descriptor. The actual secret is delivered through `CredentialChannel`
  at execution time.
- All validation in `ProxyConfigValidator` throws `InvalidArgumentException`
  with descriptive codes, never leaking credential content.

## Tests

### `tests/Unit/Transport/ProxyConfigTest.php`
- `ProxyConfig::jsonSerialize()` roundtrip via `fromJsonV1()`.
- Each `TransportOptions` subclass roundtrip.

### `tests/Unit/Transport/ProxyConfigValidatorTest.php`
- Valid SOCKS5 config with StdinChannel → no exception.
- Valid HTTP CONNECT config with FileDescriptorChannel → no exception.
- SocksOptions with `ProxyDns` on `Socks5` (not `5h`) → exception.
- `enableUdpAssociate = true` on `HttpConnectOptions` → exception.
- Missing required TransportOptions subclass for scheme → exception.
- TLS verifyPeer false in production context → exception (unless trusted).

### `tests/Unit/Transport/ProbeContextBuilderTest.php`
- Build from AuditTaskV1 with sealed credential → correct host/port/probe.
- Build from AuditTaskV1 with credential reference → correct channel type.
- AccessIdentity not propagated to ProbeExecutionContext (assert it's absent).
- TenantId not propagated to ProbeExecutionContext (assert it's absent).

## Verify

```
timeout 120 ../../../vendor/bin/pest --testsuite Unit
timeout 120 ../../../vendor/bin/pest
```

## Out-of-scope

- Actual network connections (T16 — Transport Adapters).
- Credential unsealing / decryption (T16).
- Tool implementations (Stage 4 — T19+).
- MTProto handshake details (Stage 8).
