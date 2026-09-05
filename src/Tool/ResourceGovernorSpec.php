<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Tool;

use InvalidArgumentException;

/**
 * Worker-side Resource Governor budget (plan §11.39 п.15): without these caps
 * N jobs × M processes is a self-inflicted DoS of the checker node (INV-019).
 * Container-level limits (memory/cpu/pids, read-only fs, no-new-privileges)
 * are enforced additionally by the runtime.
 */
final readonly class ResourceGovernorSpec
{
    public function __construct(
        public readonly int $maxConcurrentProbes,
        public readonly int $maxProcesses,
        public readonly int $maxMemoryBytes,
        public readonly int $maxCpuPercent,
        public readonly int $maxExecutionTimeSeconds,
        public readonly int $maxOutputBytes,
        public readonly int $maxStdinBytes,
        public readonly int $maxFileDescriptors,
    ) {
        foreach (get_object_vars($this) as $name => $value) {
            $value < 1 && throw new InvalidArgumentException("ResourceGovernorSpec {$name} must be positive.");
        }
    }
}
