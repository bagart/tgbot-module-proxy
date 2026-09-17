<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Export;

use BAGArt\ProxyOperations\Domain\Identity\ProxyProtocol;

/**
 * Materialized export view — one row in an export (plan §11.28).
 * Credentials are masked by default; plaintext only when explicitly confirmed.
 */
final readonly class ExportView
{
    public function __construct(
        public string $accessId,
        public ProxyProtocol $protocol,
        public string $host,
        public int $port,
        public string $credential,
        public ?float $healthScore,
        public string $accessState,
        public ?bool $telegramUsable,
        public ?string $country,
    ) {}
}
