<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Domain\Snapshot;

use JsonSerializable;
use RuntimeException;

/**
 * Single Telegram data center entry of a TelegramDcSet (plan §11.35 п.13).
 */
final readonly class TelegramDc implements JsonSerializable
{
    public const int SCHEMA_VERSION = 1;

    /**
     * @param  list<string>  $addresses
     * @param  list<int>  $ports
     */
    public function __construct(
        public readonly int $dcId,
        public readonly array $addresses,
        public readonly array $ports,
        public readonly bool $enabled,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'dcId' => $this->dcId,
            'addresses' => $this->addresses,
            'ports' => $this->ports,
            'enabled' => $this->enabled,
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
            default => throw new RuntimeException('Unsupported TelegramDc schemaVersion: '.var_export($data['schemaVersion'], true)),
        };
    }

    /**
     * @param  array<string,mixed>  $data
     */
    private static function fromJsonV1(array $data): self
    {
        return new self(
            dcId: (int) $data['dcId'],
            addresses: array_values(array_map(strval(...), (array) $data['addresses'])),
            ports: array_values(array_map(intval(...), (array) $data['ports'])),
            enabled: (bool) $data['enabled'],
        );
    }
}
