# T16 — Transport Adapters: HTTP CONNECT, SOCKS4/4a/5/5h

Plan ref: plan §§11.39 пп.3,5–6,8, §11.4–11.5, task #76 (`[ASK-TRANSPORT]`).
Depends on: T15 (ProxyConfig DTOs), T91 (Tool contracts).

## Goal

Implement the transport adapters that perform actual network connections through
proxy protocols. Each adapter knows how to:
1. Connect to the proxy endpoint.
2. Authenticate (via stdin-delivered credentials).
3. Establish the tunnel to the target host:port.
4. Return a stream/socket handle for the caller.

These adapters are called by `ProbeTool` implementations (Stage 4) and by the
curl/binary CLI wrapper. They use `php-async-kernel-client` for Fiber-based
async I/O (plan §11.30: "all network checks on the ASK transport").

UDP ASSOCIATE and DNS modes are separate — not in this task.

## Deliverables (file-by-file)

### Transport Adapters — `src/Transport/Adapters/`

- **`TransportAdapterContract.php`**
  ```php
  namespace BAGArt\ProxyOperations\Transport\Adapters;

  use BAGArt\ProxyOperations\Transport\ProxyConfig;

  interface TransportAdapterContract
  {
      /**
       * Establish a connection to $targetHost:$targetPort through the proxy
       * described by $config. Returns a resource stream handle.
       *
       * @throws TransportConnectionException on failure.
       * @throws TransportAuthException if proxy authentication fails.
       */
      public function connect(
          ProxyConfig $config,
          string $targetHost,
          int $targetPort,
      ): mixed; // resource|GuzzleStreamInterface — platform stream handle

      /**
       * Close the connection and release resources. Idempotent.
       */
      public function close(): void;
  }
  ```

- **`HttpConnectAdapter.php`** — HTTP/HTTPS CONNECT tunnel:
  ```
  Implementation:
  - Establish TCP connection to proxy (via ASK transport / Guzzle / curl).
  - Send CONNECT targetHost:targetPort HTTP/1.1.
  - Wait for 200 response (tunnel established).
  - For HTTPS target: upgrade to TLS over the tunnel.
  - Return tunnel stream.
  - Credential via CredentialChannel → Fed to proxy auth header (Proxy-Authorization).
  ```
  - Validates `ProxyConfig.scheme ∈ {Http, Https}`.
  - Validates `TransportOptions` is `HttpConnectOptions`.

- **`Socks4Adapter.php`** — SOCKS4/SOCKS4a:
  ```
  Implementation:
  - TCP connect to proxy.
  - SOCKS4 handshake: version=0x01, connect command=0x01, port, IP (4 bytes for SOCKS4,
    0.0.0.1 + domain for SOCKS4a).
  - If auth: USERID byte string.
  - Wait for response: version=0x00, status=0x5A (granted).
  - Return connected stream.
  ```
  - Validates `ProxyConfig.scheme ∈ {Socks4, Socks4a}`.
  - SOCKS4a: when target is a domain (not IP), uses `0.0.0.1` + null-terminated domain.

- **`Socks5Adapter.php`** — SOCKS5/SOCKS5h:
  ```
  Implementation:
  - TCP connect to proxy.
  - SOCKS5 greeting: version=0x05, nauth=1, methods=[0x00, 0x02] (no-auth + user/pass).
  - If proxy selects 0x02: send username/password sub-negotiation.
  - Connect request: version=0x05, cmd=0x01 (connect), rsv=0x00, atyp
    (IPv4=0x01/domain=0x03/IPv6=0x04), addr, port.
  - SOCKS5h: atyp=0x03 (domain) so proxy resolves DNS.
  - Wait for response: status=0x00 (success).
  - Return connected stream.
  ```
  - Validates `ProxyConfig.scheme ∈ {Socks5, Socks5h}`.
  - Credentials: user/pass from `ProxyCredentialRef` via `CredentialChannel`
    — fed into SOCKS5 sub-negotiation (NOT argv).

- **`DirectAdapter.php`** — direct TCP (no proxy, for judges/direct targets):
  ```
  Implementation:
  - TCP connect to targetHost:targetPort (no SOCKS/HTTP tunneling).
  - Optional TLS upgrade if TlsOptions.verifyPeer.
  - Return connected stream.
  ```

### Exceptions — `src/Transport/Adapters/`

- **`TransportConnectionException.php`**
  ```php
  namespace BAGArt\ProxyOperations\Transport\Adapters;

  use RuntimeException;

  final class TransportConnectionException extends RuntimeException {}
  ```

- **`TransportAuthException.php`**
  ```php
  namespace BAGArt\ProxyOperations\Transport\Adapters;

  use RuntimeException;

  final class TransportAuthException extends RuntimeException {}
  ```

### Resolver — `src/Transport/`

- **`TransportAdapterResolver.php`**
  ```php
  namespace BAGArt\ProxyOperations\Transport;

  use BAGArt\ProxyOperations\Transport\Adapters\TransportAdapterContract;

  /**
   * Maps ProxyProtocol → TransportAdapterContract (plan §11.39 п.20: domain
   * calls ProbeTool contract; the adapter — CLI or HTTP-runner — is an
   * implementation detail, identical across domain and worker).
   */
  final class TransportAdapterResolver
  {
      /**
       * @var array<string, TransportAdapterContract>
       */
      private array $adapters = [];

      public function register(ProxyProtocol $protocol, TransportAdapterContract $adapter): void { /* ... */ }

      public function resolve(ProxyProtocol $protocol): TransportAdapterContract
      {
          // Returns adapter or throws TransportConnectionException.
      }

      public function supported(): array
      {
          // Returns list of registered ProxyProtocol values.
      }
  }
  ```

### Credential Delivery — `src/Transport/`

- **`CredentialPayload.php`**
  ```php
  namespace BAGArt\ProxyOperations\Transport;

  /**
   * Decrypted credential material, held only in memory for the duration of
   * a single probe execution. Never logged, never serialized, zeroized on
   * scope exit (plan §11.39 п.6, INV-013).
   */
  final readonly class CredentialPayload
  {
      public function __construct(
          public readonly ?string $username,
          public readonly string $secret,
      ) {}
  }
  ```

- **`CredentialUnsealer.php`**
  ```php
  namespace BAGArt\ProxyOperations\Transport;

  use BAGArt\ProxyOperations\Wire\AuditTaskV1;
  use BAGArt\ProxyOperations\Wire\SealedCredentialPayload;

  /**
   * Unseals a SealedCredentialPayload from AuditTaskV1 into ephemeral
   * CredentialPayload. The unseal happens once per probe execution,
   * scoped to the worker process (plan §11.39 п.6).
   */
  final readonly class CredentialUnsealer
  {
      public function __construct(
          private readonly CredentialDecryptor $decryptor,
      ) {}

      public function unseal(SealedCredentialPayload $sealed): CredentialPayload
      {
          // Decrypt sealed envelope → CredentialPayload.
          // CredentialPayload lives only in memory; caller must use it
          // within the probe scope.
      }
  }
  ```

- **`CredentialDecryptor.php`**
  ```php
  namespace BAGArt\ProxyOperations\Transport;

  use BAGArt\ProxyOperations\Wire\SealedCredentialPayload;

  /**
   * Decrypts a sealed credential envelope. Production: AES-256-GCM via
   * the DEK stored in proxy_workspace_deks (T09). Test seam: injectable.
   */
  interface CredentialDecryptor
  {
      public function decrypt(SealedCredentialPayload $sealed): string;
  }
  ```

## Conventions

- All adapters implement `TransportAdapterContract` — one interface, one
  resolution path (INV-018).
- No credentials in argv/logs: SOCKS5 user/pass is sent via sub-negotiation
  bytes over the established TCP socket; HTTP Proxy-Authorization header is
  constructed from `CredentialPayload` in memory only.
- `CredentialPayload` is a short-lived value object — it exists only within
  the scope of one probe execution, never escapes to logs/exceptions/Redis.
- Each adapter validates its own protocol compatibility with `ProxyConfig`
  — no cross-protocol assumptions.
- Fiber-based async: adapters are designed to work with the ASK event loop;
  blocking calls (fsockopen, fwrite) must be wrapped in Fiber-compatible
  async wrappers where available ( Guzzle/Curl adapters from
  `php-async-kernel-client`).

## Tests

### `tests/Unit/Transport/Adapters/HttpConnectAdapterTest.php`
- Mock proxy server (PHP stream socket server on localhost) that accepts
  CONNECT request and returns 200.
- Verify: tunnel established, correct Proxy-Authorization header sent.
- Verify: invalid credentials → `TransportAuthException`.
- Verify: proxy unreachable → `TransportConnectionException`.

### `tests/Unit/Transport/Adapters/Socks5AdapterTest.php`
- Mock SOCKS5 proxy: greeting response selects method 0x02, accepts
  user/pass sub-negotiation, grants connect.
- Verify: correct SOCKS5 handshake bytes (binary comparison).
- Verify: invalid auth → `TransportAuthException` (SOCKS5 status 0xFF).
- Verify: SOCKS5h sends domain atyp=0x03 (not resolved IP).

### `tests/Unit/Transport/Adapters/Socks4AdapterTest.php`
- Mock SOCKS4 proxy: accepts CONNECT, returns 0x5A.
- Verify: SOCKS4a sends 0.0.0.1 + domain for domain targets.
- Verify: SOCKS4 sends raw IP for IP targets.

### `tests/Unit/Transport/Adapters/DirectAdapterTest.php`
- Connect to a local TCP server (PHP stream), verify raw connection.

### `tests/Unit/Transport/TransportAdapterResolverTest.php`
- Register SOCKS5 adapter, resolve ProxyProtocol::Socks5 → correct instance.
- Unregistered protocol → exception.

### `tests/Unit/Transport/CredentialUnsealerTest.php`
- Unseal a sealed envelope → correct CredentialPayload.
- Verify CredentialPayload.secret is not empty and matches original.

## Verify

```
timeout 120 ../../../vendor/bin/pest --testsuite Unit
timeout 120 ../../../vendor/bin/pest
```

## Out-of-scope

- UDP ASSOCIATE transport (T17).
- DNS resolution through SOCKS5 proxy (T17).
- MTProto handshake (Stage 8).
- ProbeTool implementations that use these adapters (Stage 4 — T19+).
- Resource Governor enforcement (T18).
- ASK daemon/tickable wiring (T18).
