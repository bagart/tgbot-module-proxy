<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Domain\Snapshot;

use JsonSerializable;
use RuntimeException;

/**
 * Single judge inside a JudgeSetSnapshot (plan §11.8 JudgeDefinition shape):
 * identity, placement, protocol limits and trust level.
 */
final readonly class JudgeDescriptor implements JsonSerializable
{
    public const int SCHEMA_VERSION = 1;

    /**
     * @param  list<string>  $capabilities  Probe kinds this judge can serve (e.g. liveness, anonymity, marker).
     */
    public function __construct(
        public readonly string $id,
        public readonly string $url,
        public readonly string $region,
        public readonly string $protocol,
        public readonly array $capabilities,
        public readonly int $rateLimitPerMinute,
        public readonly JudgeTrustTier $trustTier,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'url' => $this->url,
            'region' => $this->region,
            'protocol' => $this->protocol,
            'capabilities' => $this->capabilities,
            'rateLimitPerMinute' => $this->rateLimitPerMinute,
            'trustTier' => $this->trustTier->value,
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
            default => throw new RuntimeException('Unsupported JudgeDescriptor schemaVersion: '.var_export($data['schemaVersion'], true)),
        };
    }

    /**
     * @param  array<string,mixed>  $data
     */
    private static function fromJsonV1(array $data): self
    {
        return new self(
            id: (string) $data['id'],
            url: (string) $data['url'],
            region: (string) $data['region'],
            protocol: (string) $data['protocol'],
            capabilities: array_values(array_map(strval(...), (array) $data['capabilities'])),
            rateLimitPerMinute: (int) $data['rateLimitPerMinute'],
            trustTier: JudgeTrustTier::from((string) $data['trustTier']),
        );
    }
}
