<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Transport\Adapters;

use JsonSerializable;

/**
 * Result of a UDP ASSOCIATE attempt through a SOCKS5 proxy.
 */
final readonly class UdpAssociateResult implements JsonSerializable
{
    public const int SCHEMA_VERSION = 1;

    /**
     * @param  array<string, float>  $timingsMs
     */
    public function __construct(
        public readonly bool $supported,
        public readonly ?string $relayHost,
        public readonly ?int $relayPort,
        public readonly ?string $error,
        public readonly array $timingsMs,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'supported' => $this->supported,
            'relayHost' => $this->relayHost,
            'relayPort' => $this->relayPort,
            'error' => $this->error,
            'timingsMs' => $this->timingsMs,
            'schemaVersion' => self::SCHEMA_VERSION,
        ];
    }
}
