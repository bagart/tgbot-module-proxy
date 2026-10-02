<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Transport\Adapters;

use JsonSerializable;

/**
 * Result of a single UDP datagram exchange through a SOCKS5 relay.
 */
final readonly class UdpDatagramResult implements JsonSerializable
{
    public const int SCHEMA_VERSION = 1;

    public function __construct(
        public readonly bool $success,
        public readonly ?string $response,
        public readonly ?string $error,
        public readonly float $latencyMs,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'success' => $this->success,
            'response' => $this->response,
            'error' => $this->error,
            'latencyMs' => $this->latencyMs,
            'schemaVersion' => self::SCHEMA_VERSION,
        ];
    }
}
