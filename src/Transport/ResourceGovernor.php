<?php

declare(strict_types=1);

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
    ) {
    }

    /**
     * Check if a new connection is allowed under current resource pressure.
     */
    public function canOpenConnection(): bool
    {
        return $this->activeConnections < $this->spec->maxConcurrentProbes;
    }

    public function connectionOpened(): void
    {
        $this->activeConnections++;
    }

    public function connectionClosed(): void
    {
        $this->activeConnections = max(0, $this->activeConnections - 1);
    }

    public function activeConnections(): int
    {
        return $this->activeConnections;
    }

    public function totalBytesIn(): int
    {
        return $this->totalBytesIn;
    }

    public function totalBytesOut(): int
    {
        return $this->totalBytesOut;
    }

    public function addBytesIn(int $bytes): void
    {
        $this->totalBytesIn += $bytes;
    }

    public function addBytesOut(int $bytes): void
    {
        $this->totalBytesOut += $bytes;
    }

    /**
     * Enforce output byte cap: returns the data if within limits, or
     * truncated data with flag.
     */
    public function enforceOutputLimit(string $data): ResourceLimitResult
    {
        $originalSize = strlen($data);

        if ($originalSize <= $this->spec->maxOutputBytes) {
            return new ResourceLimitResult(
                data: $data,
                truncated: false,
                originalSize: $originalSize,
            );
        }

        return new ResourceLimitResult(
            data: substr($data, 0, $this->spec->maxOutputBytes),
            truncated: true,
            originalSize: $originalSize,
        );
    }

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
