<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Domain\Snapshot;

use JsonSerializable;
use RuntimeException;

/**
 * Version record of a single contract in the Contract Version Matrix
 * (plan §11.12).
 */
final readonly class ContractVersionEntry implements JsonSerializable
{
    public const int SCHEMA_VERSION = 1;

    /**
     * @param  list<int>  $compatibleVersions  Versions this deployment can interoperate with.
     */
    public function __construct(
        public readonly string $name,
        public readonly int $currentVersion,
        public readonly ContractCompatibility $compatibility,
        public readonly array $compatibleVersions,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'name' => $this->name,
            'currentVersion' => $this->currentVersion,
            'compatibility' => $this->compatibility->value,
            'compatibleVersions' => $this->compatibleVersions,
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
            default => throw new RuntimeException('Unsupported ContractVersionEntry schemaVersion: '.var_export($data['schemaVersion'], true)),
        };
    }

    /**
     * @param  array<string,mixed>  $data
     */
    private static function fromJsonV1(array $data): self
    {
        return new self(
            name: (string) $data['name'],
            currentVersion: (int) $data['currentVersion'],
            compatibility: ContractCompatibility::from((string) $data['compatibility']),
            compatibleVersions: array_values(array_map(intval(...), (array) $data['compatibleVersions'])),
        );
    }
}
