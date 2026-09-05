<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Wire;

use JsonSerializable;
use RuntimeException;

/**
 * Opaque credential handle resolved by the worker's local unseal service
 * (plan §11.35 п.5, variant B reserved for external workers). The worker never
 * receives KEK/DEK material (INV-004): the handle alone is useless outside the
 * checker node that issued it.
 */
final readonly class CredentialReference implements JsonSerializable
{
    public const int SCHEMA_VERSION = 1;

    public function __construct(
        public readonly string $handle,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'handle' => $this->handle,
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
            default => throw new RuntimeException('Unsupported CredentialReference schemaVersion: '.var_export($data['schemaVersion'], true)),
        };
    }

    /**
     * @param  array<string,mixed>  $data
     */
    private static function fromJsonV1(array $data): self
    {
        return new self(handle: (string) $data['handle']);
    }
}
