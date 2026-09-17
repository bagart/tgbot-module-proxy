<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Export;

/**
 * Configuration DTO for observation retention policy (plan §11.21, #48/#49).
 * No deletion logic in MVP — the flag exists for future enforcement.
 */
final readonly class ObservationRetentionPolicy
{
    public function __construct(
        public bool $enabled = false,
        public int $retentionDays = 365,
        public bool $partitioning = true,
    ) {}
}
