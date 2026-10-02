<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Transport;

use JsonSerializable;

/**
 * Result of a DNS leak probe check.
 */
final readonly class DnsLeakResult implements JsonSerializable
{
    public const int SCHEMA_VERSION = 1;

    /**
     * @param  list<string>  $resolvedIps
     */
    public function __construct(
        public readonly array $resolvedIps,
        public readonly bool $leaked,
        public readonly SocksDnsMode $mode,
        public readonly float $latencyMs,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'resolvedIps' => $this->resolvedIps,
            'leaked' => $this->leaked,
            'mode' => $this->mode->value,
            'latencyMs' => $this->latencyMs,
            'schemaVersion' => self::SCHEMA_VERSION,
        ];
    }
}
