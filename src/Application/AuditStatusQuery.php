<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Application;

/**
 * Audit status query (plan §11.10, §11.27).
 */
final readonly class AuditStatusQuery implements ApplicationQuery
{
    public function __construct(
        public string $tenantId,
        public string $jobId,
    ) {
    }
}
