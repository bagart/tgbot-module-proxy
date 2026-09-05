<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Transport;

use JsonSerializable;
use RuntimeException;

/**
 * HTTP CONNECT tunnel options (plan §11.5).
 */
final readonly class HttpConnectOptions extends TransportOptions implements JsonSerializable
{
    public const int SCHEMA_VERSION = 1;

    public function __construct(
        public readonly bool $tunnel = true,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'tunnel' => $this->tunnel,
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
            default => throw new RuntimeException('Unsupported HttpConnectOptions schemaVersion: '.var_export($data['schemaVersion'], true)),
        };
    }

    /**
     * @param  array<string,mixed>  $data
     */
    private static function fromJsonV1(array $data): self
    {
        return new self(
            tunnel: (bool) ($data['tunnel'] ?? true),
        );
    }
}
