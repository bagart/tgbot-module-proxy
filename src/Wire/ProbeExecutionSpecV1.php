<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Wire;

use BAGArt\ProxyOperations\Domain\Probe\ProbeProfile;
use BAGArt\ProxyOperations\Domain\Probe\ProbeType;
use InvalidArgumentException;
use JsonSerializable;
use RuntimeException;

/**
 * One probe execution inside an AuditTask: type, profile and the minimal
 * ProbeInput source per plan §11.39 п.5 — the external tool sees only what it
 * needs for this single probe, never domain entities (INV-011).
 */
final readonly class ProbeExecutionSpecV1 implements JsonSerializable
{
    public const int SCHEMA_VERSION = 1;

    /**
     * @param  string  $target  Probe target descriptor (judge URL, DC host, URI) resolved by the runner into minimal ProbeInput.
     * @param  int  $timeoutMs  Per-probe wall-clock limit enforced by the worker.
     * @param  int  $maxOutputBytes  Output cap enforced by the worker's Resource Governor (INV-019).
     */
    public function __construct(
        public readonly ProbeType $probeType,
        public readonly ProbeProfile $profile,
        public readonly string $target,
        public readonly int $timeoutMs,
        public readonly int $maxOutputBytes,
    ) {
        if ($this->timeoutMs < 1) {
            throw new InvalidArgumentException('Probe timeoutMs must be >= 1.');
        }

        if ($this->maxOutputBytes < 1) {
            throw new InvalidArgumentException('Probe maxOutputBytes must be >= 1.');
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'probeType' => $this->probeType->value,
            'profile' => $this->profile->value,
            'target' => $this->target,
            'timeoutMs' => $this->timeoutMs,
            'maxOutputBytes' => $this->maxOutputBytes,
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
            default => throw new RuntimeException('Unsupported ProbeExecutionSpec schemaVersion: '.var_export($data['schemaVersion'], true)),
        };
    }

    /**
     * @param  array<string,mixed>  $data
     */
    private static function fromJsonV1(array $data): self
    {
        return new self(
            probeType: ProbeType::from((string) $data['probeType']),
            profile: ProbeProfile::from((string) $data['profile']),
            target: (string) $data['target'],
            timeoutMs: (int) $data['timeoutMs'],
            maxOutputBytes: (int) $data['maxOutputBytes'],
        );
    }
}
