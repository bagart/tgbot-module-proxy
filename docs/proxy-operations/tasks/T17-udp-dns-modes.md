# T17 — UDP ASSOCIATE and DNS Resolution Modes

Plan ref: plan §§11.5, 11.39 пп.3,5,8, task #76 (`[ASK-TRANSPORT]`).
Depends on: T16 (Transport Adapters — SOCKS5 adapter, `TransportAdapterContract`),
T15 (ProxyConfig DTOs — `SocksOptions`, `SocksDnsMode`).

## Goal

Implement the two transport concepts that are explicitly separate from TCP
CONNECT (plan §11.5): UDP ASSOCIATE for SOCKS5 and DNS resolution modes
(LOCAL_DNS / REMOTE_DNS / PROXY_DNS). These are distinct probe capabilities
that compose with, but are not part of, the TCP connection adapters.

UDP ASSOCIATE: establishes a UDP relay through the SOCKS5 proxy for datagram
relay to arbitrary targets. Used for `ProbeType::UdpAssociate`.

DNS modes: resolve hostnames through different paths — local system DNS, remote
DNS via SOCKS5 (SOCKS5 remote), or DNS through the proxy itself (SOCKS5h).
Used for `ProbeType::DnsResolution` and as a building block for DNS leak
detection.

## Deliverables (file-by-file)

### UDP ASSOCIATE — `src/Transport/Adapters/`

- **`Socks5UdpAdapter.php`**
  ```php
  namespace BAGArt\ProxyOperations\Transport\Adapters;

  use BAGArt\ProxyOperations\Transport\ProxyConfig;

  /**
   * UDP ASSOCIATE through SOCKS5 proxy (plan §§11.5, 11.39 п.5).
   * Establishes a TCP control connection, sends UDP ASSOCIATE request,
   * receives the relay address:port, then sends/receives UDP datagrams
   * through the relay.
   *
   * Scope: capability probe only — whether the proxy supports UDP ASSOCIATE.
   * Full UDP relay session management is a higher-layer concern.
   */
  final class Socks5UdpAdapter
  {
      /**
       * Establish UDP ASSOCIATE and return relay information.
       *
       * Steps:
       * 1. TCP connect to SOCKS5 proxy.
       * 2. SOCKS5 handshake (auth via CredentialChannel).
       * 3. Send UDP ASSOCIATE request (cmd=0x03).
       * 4. Receive relay address:port from response.
       * 5. Send a test UDP datagram through the relay.
       * 6. Return UdpAssociateResult with relay address and success/failure.
       *
       * @throws TransportConnectionException if UDP ASSOCIATE fails.
       * @throws TransportAuthException if auth fails.
       */
      public function associate(
          ProxyConfig $config,
          string $targetHost,
          int $targetPort,
      ): UdpAssociateResult { /* ... */ }

      /**
       * Send a UDP datagram through the established relay and wait for response.
       *
       * @return UdpDatagramResult with response bytes and timing.
       */
      public function sendDatagram(
          UdpRelayHandle $relay,
          string $data,
          string $targetHost,
          int $targetPort,
      ): UdpDatagramResult { /* ... */ }

      /**
       * Close the UDP relay and release the TCP control connection.
       */
      public function close(): void { /* ... */ }
  }
  ```

- **`UdpAssociateResult.php`** — result of UDP ASSOCIATE attempt:
  ```php
  namespace BAGArt\ProxyOperations\Transport\Adapters;

  final readonly class UdpAssociateResult implements JsonSerializable
  {
      public const int SCHEMA_VERSION = 1;

      public function __construct(
          public readonly bool $supported,
          public readonly ?string $relayHost,
          public readonly ?int $relayPort,
          public readonly ?string $error,
          /** @var array<string, float> */
          public readonly array $timingsMs,
      ) {}
  }
  ```

- **`UdpRelayHandle.php`** — opaque handle for an active UDP relay:
  ```php
  namespace BAGArt\ProxyOperations\Transport\Adapters;

  final readonly class UdpRelayHandle
  {
      public function __construct(
          public readonly string $relayHost,
          public readonly int $relayPort,
          public readonly mixed $controlSocket,
          public readonly mixed $udpSocket,
      ) {}
  }
  ```

- **`UdpDatagramResult.php`** — result of a single UDP datagram exchange:
  ```php
  namespace BAGArt\ProxyOperations\Transport\Adapters;

  final readonly class UdpDatagramResult implements JsonSerializable
  {
      public const int SCHEMA_VERSION = 1;

      public function __construct(
          public readonly bool $success,
          public readonly ?string $response,
          public readonly ?string $error,
          public readonly float $latencyMs,
      ) {}
  }
  ```

### DNS Resolution — `src/Transport/`

- **`DnsResolverContract.php`**
  ```php
  namespace BAGArt\ProxyOperations\Transport;

  interface DnsResolverContract
  {
      /**
       * Resolve hostname to IP addresses through the configured DNS mode.
       *
       * @return list<string> resolved IP addresses.
       * @throws DnsResolutionException on failure.
       */
      public function resolve(string $hostname): array;

      public function mode(): SocksDnsMode;
  }
  ```

- **`LocalDnsResolver.php`** — system DNS:
  ```php
  namespace BAGArt\ProxyOperations\Transport;

  final class LocalDnsResolver implements DnsResolverContract
  {
      public function resolve(string $hostname): array
      {
          // Uses gethostbyaddr / dns_get_record / PHP native DNS.
          // No proxy involvement.
      }

      public function mode(): SocksDnsMode
      {
          return SocksDnsMode::Local;
      }
  }
  ```

- **`RemoteDnsResolver.php`** — DNS via SOCKS5 remote resolution:
  ```php
  namespace BAGArt\ProxyOperations\Transport;

  final class RemoteDnsResolver implements DnsResolverContract
  {
      public function __construct(
          private readonly Socks5Adapter $socks5Adapter,
          private readonly ProxyConfig $proxyConfig,
      ) {}

      public function resolve(string $hostname): array
      {
          // Open a SOCKS5 connection with atyp=0x03 (domain) and target port 53.
          // The SOCKS5 proxy resolves the domain and connects to the DNS server.
          // Return the resolved IP from the SOCKS5 connect response.
          // This is a SOCKS5 "remote DNS" — the proxy does the resolution.
      }

      public function mode(): SocksDnsMode
      {
          return SocksDnsMode::Remote;
      }
  }
  ```

- **`ProxyDnsResolver.php`** — DNS through the proxy (SOCKS5h semantics):
  ```php
  namespace BAGArt\ProxyOperations\Transport;

  final class ProxyDnsResolver implements DnsResolverContract
  {
      public function __construct(
          private readonly Socks5Adapter $socks5Adapter,
          private readonly ProxyConfig $proxyConfig,
      ) {}

      public function resolve(string $hostname): array
      {
          // Connect to proxy, send SOCKS5 CONNECT with atyp=0x03 (domain)
          // and target port 53, then send DNS query over the tunnel.
          // SOCKS5h proxies resolve the domain — the client never sees the
          // IP until the proxy connects.
      }

      public function mode(): SocksDnsMode
      {
          return SocksDnsMode::ProxyDns;
      }
  }
  ```

- **`DnsResolverFactory.php`**
  ```php
  namespace BAGArt\ProxyOperations\Transport;

  use BAGArt\ProxyOperations\Transport\ProxyConfig;

  final readonly class DnsResolverFactory
  {
      public function create(ProxyConfig $config): DnsResolverContract
      {
          // If config.transportOptions is SocksOptions:
          //   - ProxyDns mode → ProxyDnsResolver
          //   - Remote mode → RemoteDnsResolver
          //   - Local mode → LocalDnsResolver
          // For non-SOCKS protocols → LocalDnsResolver (default).
      }
  }
  ```

### DNS Leak Probe — `src/Transport/`

- **`DnsLeakProbe.php`**
  ```php
  namespace BAGArt\ProxyOperations\Transport;

  /**
   * Performs DNS leak detection: resolve a hostname through the proxy's DNS
   * path and compare the resolved IPs against the expected resolver identity.
   * If the resolved IPs don't match what the proxy's resolver would produce,
   * the DNS query leaked to the local network.
   */
  final readonly class DnsLeakProbe
  {
      public function __construct(
          private readonly DnsResolverContract $resolver,
      ) {}

      /**
       * Resolve $hostname and check for DNS leaks.
       *
       * @return DnsLeakResult with resolved IPs and leak assessment.
       */
      public function check(string $hostname): DnsLeakResult { /* ... */ }
  }
  ```

- **`DnsLeakResult.php`**
  ```php
  namespace BAGArt\ProxyOperations\Transport;

  final readonly class DnsLeakResult implements JsonSerializable
  {
      public const int SCHEMA_VERSION = 1;

      public function __construct(
          public readonly array $resolvedIps,
          public readonly bool $leaked,
          public readonly SocksDnsMode $mode,
          public readonly float $latencyMs,
      ) {}
  }
  ```

## Conventions

- UDP ASSOCIATE is a capability probe (does the proxy support it?), not a
  full relay session. The relay session management is a higher-layer concern
  (Stage 4+).
- DNS resolution modes are strictly separated per §11.5: "UDP ASSOCIATE
  capability ≠ DNS leak absence; SOCKS5h ≠ UDP ASSOCIATE."
- `DnsResolverContract` returns `list<string>` — raw IP addresses, no
  interpretation. Interpretation (leak detection, scoring) is in the
  evidence pipeline (Stage 4+).
- Credential handling follows T16 patterns: SOCKS5 auth is via sub-negotiation
  bytes, never argv.

## Tests

### `tests/Unit/Transport/Adapters/Socks5UdpAdapterTest.php`
- Mock SOCKS5 proxy: TCP greeting → auth → UDP ASSOCIATE (cmd=0x03) →
  returns relay address:port.
- Verify: UDP ASSOCIATE request bytes (binary comparison).
- Verify: relay address received correctly.
- Verify: proxy rejecting UDP ASSOCIATE → `TransportConnectionException`.

### `tests/Unit/Transport/DnsResolverFactoryTest.php`
- SocksOptions with ProxyDns → `ProxyDnsResolver`.
- SocksOptions with Remote → `RemoteDnsResolver`.
- SocksOptions with Local → `LocalDnsResolver`.
- HttpConnectOptions → `LocalDnsResolver` (default).

### `tests/Unit/Transport/LocalDnsResolverTest.php`
- Resolve localhost → returns 127.0.0.1.
- Mode → `SocksDnsMode::Local`.

### `tests/Unit/Transport/DnsLeakProbeTest.php`
- Inject mock resolver returning known IPs → verify leak assessment.
- Verify `DnsLeakResult` serializes correctly.

## Verify

```
timeout 120 ../../../vendor/bin/pest --testsuite Unit
timeout 120 ../../../vendor/bin/pest
```

## Out-of-scope

- MTProto handshake (Stage 8).
- Full UDP relay session lifecycle (higher-layer concern, Stage 4+).
- ProbeTool implementations (Stage 4).
- Resource Governor (T18).
- ASK daemon/tickable wiring (T18).
