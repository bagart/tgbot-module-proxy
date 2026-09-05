<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Transport;

use JsonSerializable;
use RuntimeException;

/**
 * SOCKS-specific transport options (plan §11.5): UDP ASSOCIATE and DNS mode.
 */
final readonly class SocksOptions extends TransportOptions implements JsonSerializable
{
    public const int SCHEMA_VERSION = 1;

    public function __construct(
        public readonly bool $enableUdpAssociate = false,
        public readonly SocksDnsMode $dnsMode = SocksDnsMode::Local,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'enableUdpAssociate' => $this->enableUdpAssociate,
            'dnsMode' => $this->dnsMode->value,
            'schemaVersion' => self::SCHEMA_VERSION,
        ];
    }

    /**
     * @param  array<string,mixed>  $data
     *
     * @throws RuntimeException If the format is not recognized.
     */
    public static function fromJson(array $data): self
    {
        return match ($data['schemaVersion'] ?? self::SCHEMA_VERSION) {
            self::SCHEMA_VERSION => self::fromJsonV1($data),
            default => throw new RuntimeException('Unsupported SocksOptions schemaVersion: '.var_export($data['schemaVersion'], true)),
        };
    }

    /**
     * @param  array<string,mixed>  $data
     */
    private static function fromJsonV1(array $data): self
    {
        return new self(
            enableUdpAssociate: (bool) ($data['enableUdpAssociate'] ?? false),
            dnsMode: SocksDnsMode::from((string) ($data['dnsMode'] ?? SocksDnsMode::Local->value)),
        );
    }
}
