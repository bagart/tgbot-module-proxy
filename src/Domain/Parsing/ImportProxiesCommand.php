<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Domain\Parsing;

use JsonSerializable;

/**
 * Shared application command that imports proxy text into the inventory.
 *
 * Consumed by bot /import, Import Wizard, API, CLI, and feeds — one code
 * path for all entry points (plan §11.10, §11.28). The command is a pure
 * DTO; it carries no behaviour beyond serialization.
 *
 * INV-007: the `text` field contains raw proxy lines — the parser never
 * encrypts; credential sealing happens in the model layer.
 */
final readonly class ImportProxiesCommand implements JsonSerializable
{
    public const int SCHEMA_VERSION = 1;

    public function __construct(
        public readonly string $text,
        public readonly ?string $sourceLabel,
        public readonly int $tenantId,
        public readonly ?string $idempotencyKey,
    ) {}

    public function jsonSerialize(): array
    {
        return [
            'text' => $this->text,
            'sourceLabel' => $this->sourceLabel,
            'tenantId' => $this->tenantId,
            'idempotencyKey' => $this->idempotencyKey,
            'schemaVersion' => self::SCHEMA_VERSION,
        ];
    }

    public static function fromJson(array $data): self
    {
        $schemaVersion = $data['schemaVersion'] ?? 1;

        return match ($schemaVersion) {
            1 => self::fromJsonV1($data),
            default => throw new \RuntimeException("Unsupported ImportProxiesCommand schemaVersion: {$schemaVersion}"),
        };
    }

    private static function fromJsonV1(array $data): self
    {
        return new self(
            text: (string) $data['text'],
            sourceLabel: $data['sourceLabel'] ?? null,
            tenantId: (int) $data['tenantId'],
            idempotencyKey: $data['idempotencyKey'] ?? null,
        );
    }
}
