<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Transport;

use BAGArt\AsyncKernel\ASKShutdownContext;
use BAGArt\AsyncKernel\Contracts\Daemons\ASKDaemonContract;
use BAGArt\AsyncKernel\Contracts\Daemons\ASKTickableContract;
use BAGArt\AsyncKernel\Contracts\Daemons\ASKWarmableContract;
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
final class TransportCapabilityDaemon implements ASKDaemonContract, ASKWarmableContract, WithASKTickableContract
{
    private bool $isShuttingDown = false;

    private bool $warmed = false;

    public function __construct(
        private readonly CapabilityProbeRunner $probeRunner,
        private readonly ResourceGovernor $governor,
        private readonly string $name = 'TransportCapabilityDaemon',
    ) {}

    public function warm(): void
    {
        $this->warmed = true;
        $this->governor->flush();
    }

    public function startup(): void
    {
        // Daemon is ready — warm() was called by AsyncKernel during addDaemon().
    }

    public function shutdown(ASKShutdownContext $context): bool
    {
        if (! $this->isShuttingDown) {
            $this->isShuttingDown = true;
        }

        $this->governor->flush();

        return true;
    }

    public function onError(\Throwable $e): void
    {
        // Log error — daemon must not crash on probe failures.
    }

    public function name(): string
    {
        return $this->name;
    }

    public function isShuttingDown(): bool
    {
        return $this->isShuttingDown;
    }

    public function isWarmed(): bool
    {
        return $this->warmed;
    }

    /**
     * @return ASKTickableContract[]
     */
    public function tickable(): array
    {
        return [];
    }
}
