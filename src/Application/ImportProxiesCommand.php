<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Application;

/**
 * Import proxies command — application layer (plan §11.10, §11.28).
 */
final readonly class ImportProxiesCommand implements ApplicationCommand
{
    public function __construct(
        public string $tenantId,
        public ImportSource $source,
        public string $payload,
        public ?string $sourceLabel = null,
    ) {
    }
}
