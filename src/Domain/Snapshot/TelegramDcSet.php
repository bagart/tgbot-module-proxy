<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Domain\Snapshot;

use JsonSerializable;
use RuntimeException;

/**
 * Versioned Telegram DC list snapshot (plan §11.35 п.13) — source of truth for
 * telegram connectivity probes. The version participates in the cache/evidence
 * identity of TG probes; changing the DC list invalidates old TG observations.
 */
final readonly class TelegramDcSet implements JsonSerializable
{
    public const int SCHEMA_VERSION = 1;

    /**
     * @param  list<TelegramDc>  $dcs
     * @param  string  $frozenAt  ISO 8601 timestamp when the set was frozen.
     */
    public function __construct(
        public readonly int $version,
        public readonly array $dcs,
        public readonly string $frozenAt,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'version' => $this->version,
            'dcs' => array_map(static fn (TelegramDc $dc): array => $dc->jsonSerialize(), $this->dcs),
            'frozenAt' => $this->frozenAt,
            'schemaVersion' => self::SCHEMA_VERSION,
        ];
    }

    /**
     * @param  array<string,mixed>  $data
     *
     * @throws RuntimeException If the format is not recognized or the version field is missing.
     */
    public static function fromJson(array $data): self
    {
        if (! array_key_exists('version', $data)) {
            throw new RuntimeException('TelegramDcSet payload is missing the mandatory version field.');
        }

        return match ($data['schemaVersion'] ?? self::SCHEMA_VERSION) {
            self::SCHEMA_VERSION => self::fromJsonV1($data),
            default => throw new RuntimeException('Unsupported TelegramDcSet schemaVersion: '.var_export($data['schemaVersion'], true)),
        };
    }

    /**
     * @param  array<string,mixed>  $data
     */
    private static function fromJsonV1(array $data): self
    {
        return new self(
            version: (int) $data['version'],
            dcs: array_values(array_map(
                static fn (array $dc): TelegramDc => TelegramDc::fromJson($dc),
                (array) $data['dcs'],
            )),
            frozenAt: (string) $data['frozenAt'],
        );
    }
}
