<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Transport;

/**
 * Outcome of a ResourceGovernor output enforcement check.
 */
final readonly class ResourceLimitResult
{
    public function __construct(
        public readonly string $data,
        public readonly bool $truncated,
        public readonly int $originalSize,
    ) {
    }
}
