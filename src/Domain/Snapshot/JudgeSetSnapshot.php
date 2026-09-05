<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Domain\Snapshot;

use JsonSerializable;
use RuntimeException;

/**
 * Immutable, versioned judge set snapshot (plan §11.35 п.8). Embedded into
 * audit jobs and referenced by ProbeCacheKey via `version`; any judge change
 * (URL, timeout, headers, rate limit, capabilities) produces a new snapshot
 * version, which invalidates old cache entries.
 */
final readonly class JudgeSetSnapshot implements JsonSerializable
{
    public const int SCHEMA_VERSION = 1;

    /**
     * @param  list<JudgeDescriptor>  $judges
     * @param  string  $frozenAt  ISO 8601 timestamp when the set was frozen.
     */
    public function __construct(
        public readonly string $setId,
        public readonly int $version,
        public readonly array $judges,
        public readonly string $frozenAt,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'setId' => $this->setId,
            'version' => $this->version,
            'judges' => array_map(static fn (JudgeDescriptor $judge): array => $judge->jsonSerialize(), $this->judges),
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
            throw new RuntimeException('JudgeSetSnapshot payload is missing the mandatory version field.');
        }

        return match ($data['schemaVersion'] ?? self::SCHEMA_VERSION) {
            self::SCHEMA_VERSION => self::fromJsonV1($data),
            default => throw new RuntimeException('Unsupported JudgeSetSnapshot schemaVersion: '.var_export($data['schemaVersion'], true)),
        };
    }

    /**
     * @param  array<string,mixed>  $data
     */
    private static function fromJsonV1(array $data): self
    {
        return new self(
            setId: (string) $data['setId'],
            version: (int) $data['version'],
            judges: array_values(array_map(
                static fn (array $judge): JudgeDescriptor => JudgeDescriptor::fromJson($judge),
                (array) $data['judges'],
            )),
            frozenAt: (string) $data['frozenAt'],
        );
    }
}
