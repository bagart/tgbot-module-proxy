# T18 — Capability Probes, ASK Integration, and Worker Wiring

Plan ref: plan §§11.30, 11.39 пп.4,10,15–16, task #76 (`[ASK-TRANSPORT]`),
#81 (`[WORKER-CONTRACT]`).
Depends on: T15 (ProxyConfig DTOs), T16 (Transport Adapters), T17 (UDP/DNS),
T91 (Tool contracts), T90 (wire contracts).

## Goal

Wire the transport layer into the async kernel runtime: a daemon that
orchestrates capability probes (can this proxy handle UDP? What DNS mode does
it support?), connects transport adapters to the `ProbeTool` contract, and
enforces resource governance. This is the "glue" between the pure transport
code (T15–T17) and the checker engine (Stage 4).

Also implements: tool manifests for the transport adapters (so they appear in
the `ToolRegistry`), and the control-plane/execution-plane route handlers
for the worker node.

## Deliverables (file-by-file)

### Tool Manifests — `src/Transport/`

- **`TransportToolManifestProvider.php`**
  ```php
  namespace BAGArt\ProxyOperations\Transport;

  use BAGArt\ProxyOperations\Tool\ToolManifest;
  use BAGArt\ProxyOperations\Tool\ToolCapabilities;
  use BAGArt\ProxyOperations\Tool\ToolId;
  use BAGArt\ProxyOperations\Tool\ToolLimits;
  use BAGArt\ProxyOperations\Tool\ToolSecurity;

  /**
   * Provides ToolManifest instances for transport adapters (plan §11.39
   * пп.10–11). Each transport adapter gets a manifest that declares its
   * supported probe types, protocols, and security constraints.
   */
  final readonly class TransportToolManifestProvider
  {
      /**
       * @return list<ToolManifest>
       */
      public function manifests(): array
      {
          return [
              $this->httpConnectManifest(),
              $this->socks4Manifest(),
              $this->socks5Manifest(),
              $this->dnsResolverManifest(),
              $this->udpAssociateManifest(),
          ];
      }

      private function httpConnectManifest(): ToolManifest { /* ... */ }
      private function socks4Manifest(): ToolManifest { /* ... */ }
      private function socks5Manifest(): ToolManifest { /* ... */ }
      private function dnsResolverManifest(): ToolManifest { /* ... */ }
      private function udpAssociateManifest(): ToolManifest { /* ... */ }
  }
  ```

### Capability Probes — `src/Transport/`

- **`CapabilityProbeRunner.php`**
  ```php
  namespace BAGArt\ProxyOperations\Transport;

  use BAGArt\ProxyOperations\Transport\Adapters\TransportAdapterContract;
  use BAGArt\ProxyOperations\Transport\Adapters\UdpAssociateResult;

  /**
   * Runs capability probes against a proxy endpoint to determine what it
   * supports (plan §11.39 п.16: capability-specific containers; plan §11.4:
   * protocol capability matrix). Results update the endpoint's
   * `proxy_capabilities` table (T06).
   */
  final class CapabilityProbeRunner
  {
      public function __construct(
          private readonly TransportAdapterResolver $adapterResolver,
          private readonly DnsResolverFactory $dnsResolverFactory,
          private readonly Socks5UdpAdapter $udpAdapter,
      ) {}

      /**
       * Probe all capabilities for a given proxy config.
       * Returns a CapabilityProbeResult with per-capability findings.
       */
      public function probeAll(ProxyConfig $config): CapabilityProbeResult { /* ... */ }

      /**
       * Probe TCP connectivity through the proxy (basic reachability).
       */
      public function probeTcpConnectivity(ProxyConfig $config): CapabilityProbeResult { /* ... */ }

      /**
       * Probe UDP ASSOCIATE support (SOCKS5/SOCKS5h only).
       */
      public function probeUdpSupport(ProxyConfig $config): CapabilityProbeResult { /* ... */ }

      /**
       * Probe DNS resolution mode support and detect DNS leaks.
       */
      public function probeDnsCapability(ProxyConfig $config): CapabilityProbeResult { /* ... */ }
  }
  ```

- **`CapabilityProbeResult.php`**
  ```php
  namespace BAGArt\ProxyOperations\Transport;

  final readonly class CapabilityProbeResult implements JsonSerializable
  {
      public const int SCHEMA_VERSION = 1;

      /**
       * @param  array<string, mixed>  $capabilities  Map of capability name → result.
       */
      public function __construct(
          public readonly bool $tcpReachable,
          public readonly ?bool $udpSupported,
          public readonly ?SocksDnsMode $dnsMode,
          public readonly ?DnsLeakResult $dnsLeak,
          public readonly array $capabilities,
          /** @var array<string, float> */
          public readonly array $timingsMs,
      ) {}
  }
  ```

### Resource Governor — `src/Transport/`

- **`ResourceGovernor.php`**
  ```php
  namespace BAGArt\ProxyOperations\Transport;

  use BAGArt\ProxyOperations\Tool\ResourceGovernorSpec;

  /**
   * Enforces resource limits on transport-level operations (plan §11.39
   * п.15, INV-019): max concurrent connections, timeout enforcement,
   * output byte caps. This is the in-process governor — container-level
   * limits are enforced separately by the runtime.
   */
  final class ResourceGovernor
  {
      private int $activeConnections = 0;
      private int $totalBytesIn = 0;
      private int $totalBytesOut = 0;

      public function __construct(
          private readonly ResourceGovernorSpec $spec,
      ) {}

      /**
       * Check if a new connection is allowed under current resource pressure.
       * Returns true if allowed, false if at capacity.
       */
      public function canOpenConnection(): bool
      {
          return $this->activeConnections < $this->spec->maxConcurrentProbes;
      }

      public function connectionOpened(): void { $this->activeConnections++; }
      public function connectionClosed(): void { $this->activeConnections--; }

      /**
       * Enforce output byte cap: returns the data if within limits, or
       * truncated data with flag.
       */
      public function enforceOutputLimit(string $data): ResourceLimitResult { /* ... */ }

      /**
       * Check if execution time is within limits.
       */
      public function enforceTimeout(float $elapsedMs): bool
      {
          return ($elapsedMs / 1000) <= $this->spec->maxExecutionTimeSeconds;
      }

      /**
       * Reset all counters (e.g., at shutdown or job boundary).
       */
      public function flush(): void
      {
          $this->activeConnections = 0;
          $this->totalBytesIn = 0;
          $this->totalBytesOut = 0;
      }
  }
  ```

- **`ResourceLimitResult.php`**
  ```php
  namespace BAGArt\ProxyOperations\Transport;

  final readonly class ResourceLimitResult
  {
      public function __construct(
          public readonly string $data,
          public readonly bool $truncated,
          public readonly int $originalSize,
      ) {}
  }
  ```

### ASK Daemon — `src/Transport/`

- **`TransportCapabilityDaemon.php`**
  ```php
  namespace BAGArt\ProxyOperations\Transport;

  use BAGArt\AsyncKernel\ASKShutdownContext;
  use BAGArt\AsyncKernel\Contracts\Daemons\ASKDaemonContract;
  use BAGArt\AsyncKernel\Contracts\Daemons\ASKWarmableContract;
  use BAGArt\AsyncKernel\Contracts\Daemons\ASKTickableContract;
  use BAGArt\AsyncKernel\Contracts\Daemons\WithASKTickableContract;

  /**
   * Daemon that runs capability probes for proxy endpoints (plan §11.30:
   * Scheduler → Audit Worker → Probe Runner). Consumes a queue of
   * endpoint IDs to probe, builds ProxyConfig from DB, runs capability
   * probes, and writes results back.
   *
   * This is the entry point for capability discovery — it runs as an ASK
   * daemon in the platform PHP container, using Fiber-based async I/O.
   */
  final class TransportCapabilityDaemon implements
      ASKDaemonContract,
      WithASKTickableContract,
      ASKWarmableContract
  {
      private bool $isShuttingDown = false;

      public function __construct(
          private readonly CapabilityProbeRunner $probeRunner,
          private readonly ResourceGovernor $governor,
          private readonly string $name = 'TransportCapabilityDaemon',
      ) {}

      public function warm(): void
      {
          // Validate transport adapters are available.
          // Warm up DNS resolver factory.
          // Initialize resource governor counters.
      }

      public function startup(): void { /* ... */ }

      public function shutdown(ASKShutdownContext $context): bool
      {
          if (!$this->isShuttingDown) {
              $this->isShuttingDown = true;
          }
          // Drain in-flight probes, flush resource governor.
          $this->governor->flush();
          return true; // drained
      }

      public function onError(\Throwable $e): void { /* log */ }

      public function name(): string { return $this->name; }

      public function tickable(): array
      {
          // Return capability probe tickable(s) — the queue consumer.
          return [];
      }
  }
  ```

### Worker Node Routes — `src/Transport/`

- **`WorkerControlPlaneHandler.php`**
  ```php
  namespace BAGArt\ProxyOperations\Transport;

  use BAGArt\ProxyOperations\Tool\ControlPlaneRoute;

  /**
   * Handles control plane requests for the worker node (plan §11.39 пп.4,10):
   * GET /health, GET /ready, GET /metrics, GET /capabilities.
   * Never exposed to the internet — private docker network only.
   */
  final class WorkerControlPlaneHandler
  {
      public function __construct(
          private readonly ToolRegistry $toolRegistry,
          private readonly ResourceGovernor $governor,
          private readonly TransportToolManifestProvider $manifestProvider,
      ) {}

      public function handle(ControlPlaneRoute $route): array { /* ... */ }
  }
  ```

- **`WorkerExecutionPlaneHandler.php`**
  ```php
  namespace BAGArt\ProxyOperations\Transport;

  use BAGArt\ProxyOperations\Tool\ExecutionPlaneRoute;

  /**
   * Handles execution plane requests (plan §11.39 пп.4,18):
   * POST /v1/executions, GET /v1/executions/{id}, POST /v1/executions/{id}/cancel.
   * Delegates to ProbeTool implementations (Stage 4) — this handler is
   * the HTTP API layer, not the execution engine.
   */
  final class WorkerExecutionPlaneHandler
  {
      public function __construct(
          private readonly ResourceGovernor $governor,
      ) {}

      public function handle(ExecutionPlaneRoute $route, array $payload): array { /* ... */ }
  }
  ```

### Command — `src/Transport/`

- **`RunCapabilityProbesCommand.php`**
  ```php
  namespace BAGArt\ProxyOperations\Transport;

  use Symfony\Component\Console\Command\Command;
  use Symfony\Component\Console\Input\InputInterface;
  use Symfony\Component\Console\Output\OutputInterface;

  /**
   * CLI command to run capability probes for all (or specified) proxy
   * endpoints. Useful for manual testing and initial capability seeding.
   *
   * Usage: proxy:probe-capabilities [--endpoint-id=123] [--protocol=socks5]
   */
  final class RunCapabilityProbesCommand extends Command
  {
      protected static $defaultName = 'proxy:probe-capabilities';

      public function __construct(
          private readonly CapabilityProbeRunner $probeRunner,
          private readonly ResourceGovernor $governor,
      ) { parent::__construct(); }

      protected function execute(InputInterface $input, OutputInterface $output): int
      {
          // 1. Load endpoints from DB (filtered by --endpoint-id or --protocol).
          // 2. For each endpoint, build ProxyConfig from DB models.
          // 3. Run capability probes via CapabilityProbeRunner.
          // 4. Write results to proxy_capabilities table.
          // 5. Output summary.
          return Command::SUCCESS;
      }
  }
  ```

### Wiring — `ProxyOperationsServiceProvider.php` (updates)

Register as singletons:
- `TransportAdapterResolver` (built with all adapter instances)
- `DnsResolverFactory`
- `ResourceGovernor` (built from config `resource_governor` section)
- `CapabilityProbeRunner`
- `TransportToolManifestProvider`
- `WorkerControlPlaneHandler`
- `WorkerExecutionPlaneHandler`

Register as commands:
- `RunCapabilityProbesCommand`

Register in `config/proxy-operations.php`:
- `resource_governor` section with default limits (maxConcurrentProbes: 50,
  maxProcesses: 100, maxMemoryBytes: 512MB, maxExecutionTimeSeconds: 30, etc.)

## Conventions

- The daemon follows the ASK pattern from `telegram-bot-lib` (plan §11.30):
  explicit daemon construction in CLI commands via
  `new TransportCapabilityDaemon(...)`, NOT auto-bound by the service provider.
- `ResourceGovernor` is in-process only (INV-019): container-level limits
  (memory/cpu/pids, read-only fs, cap_drop ALL) are enforced by Docker,
  not by PHP.
- `ToolRegistry` is populated from `TransportToolManifestProvider::manifests()`
  at daemon startup — no manual registration.
- Worker control plane routes are internal only (plan §11.39 п.17): never
  exposed to the internet, only on private docker network.
- Credential unsealing (T16) is called within the daemon's tick loop, not
  in constructors — lazy connection pattern (platform rule).

## Tests

### `tests/Unit/Transport/TransportToolManifestProviderTest.php`
- All manifests are valid (name, version, capabilities non-empty).
- HTTP manifest supports `HttpLiveness` probe type.
- SOCKS5 manifest supports `UdpAssociate` and `DnsResolution` probe types.
- MTProto manifest not in transport manifests (Stage 8 adds it).

### `tests/Unit/Transport/CapabilityProbeRunnerTest.php`
- Mock adapters: probeAll returns correct capability results.
- SOCKS5 config → UDP probe attempted.
- HTTP config → UDP probe skipped.
- DNS leak detected when resolver returns unexpected IPs.

### `tests/Unit/Transport/ResourceGovernorTest.php`
- canOpenConnection returns true when below limit.
- canOpenConnection returns false at limit.
- connectionOpened/connectionClosed correctly tracks active count.
- enforceOutputLimit truncates data exceeding maxOutputBytes.
- enforceTimeout returns false when elapsed > maxExecutionTimeSeconds.
- flush resets all counters.

### `tests/Unit/Transport/TransportCapabilityDaemonTest.php`
- startup calls warm().
- shutdown flushes governor and returns true.
- tickable returns array (empty for now, will be populated when queue consumer is implemented).

### `tests/Feature/Transport/WorkerControlPlaneHandlerTest.php`
- GET /capabilities returns all registered tool manifests.
- GET /health returns 200 with status ok.
- GET /ready returns 200 when governor has capacity.
- GET /ready returns 503 when governor is at capacity.

## Verify

```
timeout 120 ../../../vendor/bin/pest --testsuite Unit
timeout 120 ../../../vendor/bin/pest
```

## Out-of-scope

- Queue consumer implementation (audit job processing — Stage 4–5).
- MTProto transport adapter (Stage 8).
- Full worker container Dockerfile / docker-compose (deploy stage).
- Tool implementations for curl/binary CLI wrappers (Stage 4).
- AuditTask → AuditResult pipeline (Stage 5).
- Cache integration (Stage 6).
