<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Application;

use BAGArt\ProxyOperations\Models\ProxyAuditJob;

/**
 * Cancel audit command (plan §11.10, §11.27).
 */
final readonly class CancelAuditCommand implements ApplicationCommand
{
    public function __construct(
        public string $tenantId,
        public string $jobId,
    ) {}
}
