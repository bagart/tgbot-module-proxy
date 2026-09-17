<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Application;

/**
 * Start audit command (plan §11.10, §11.18, §11.27).
 */
final readonly class StartAuditCommand implements ApplicationCommand
{
    public function __construct(
        public string $tenantId,
        public string $trigger = 'manual',
        public array $targetAccessIds = [],
        public ?string $requestedBy = null,
    ) {}
}
