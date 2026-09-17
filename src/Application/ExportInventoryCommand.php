<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Application;

/**
 * Export proxies command — application layer (plan §11.10, §11.28).
 */
final readonly class ExportInventoryCommand implements ApplicationCommand
{
    public function __construct(
        public string $tenantId,
        public string $format,
        public ?string $txtVariant = null,
        public bool $includeCredentials = false,
        public ?string $poolId = null,
        public bool $tgReadyOnly = false,
        public ?string $requestedBy = null,
    ) {}
}
