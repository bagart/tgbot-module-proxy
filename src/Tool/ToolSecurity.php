<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Tool;

use InvalidArgumentException;

/**
 * Security posture of the container behind a tool (plan §11.39 п.11):
 * network/filesystem/privileges constraints, enforced additionally by the
 * container runtime (read-only fs, no-new-privileges, cap_drop ALL, п.15).
 */
final readonly class ToolSecurity
{
    public function __construct(
        public readonly string $network,
        public readonly string $filesystem,
        public readonly string $privileges,
    ) {
        if ($this->network === '' || $this->filesystem === '' || $this->privileges === '') {
            throw new InvalidArgumentException('ToolSecurity fields must not be empty.');
        }
    }
}
