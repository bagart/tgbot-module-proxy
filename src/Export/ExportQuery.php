<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Export;

/**
 * Export query parameters (plan §11.28).
 */
final readonly class ExportQuery
{
    /**
     * @param  list<string>  $accessFilters  Access IDs to include (empty = all).
     */
    public function __construct(
        public string $tenantId,
        public string $format,
        public ?string $txtVariant = null,
        public bool $includeCredentials = false,
        public array $accessFilters = [],
        public ?string $poolId = null,
        public bool $tgReadyOnly = false,
    ) {}
}
