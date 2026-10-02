<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Domain\Lease;

use JsonSerializable;
use RuntimeException;

/**
 * Runtime lease handed to a consumer (plan §10.12 п.9): an opaque handle for
 * renew/release and access resolution. Carries NO credentials and NO endpoint
 * secrets (INV-013) — the consumer resolves the access through the
 * application layer.
 */
final readonly class ProxyLeaseDto implements JsonSerializable
{
    public const int SCHEMA_VERSION = 1;

    public function __construct(
        public readonly string $leaseId,
        public readonly string $accessId,
        public readonly string $tenantId,
        public readonly string $holder,
        public readonly string $purpose,
        public readonly int $acquiredAtMs,
        public readonly int $expiresAtMs,
    ) {
    }

    /**
     * Milliseconds of lease life remaining (negative = expired).
     */
    public function ttlMs(int $nowMs): int
    {
        return $this->expiresAtMs - $nowMs;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'leaseId' => $this->leaseId,
            'accessId' => $this->accessId,
            'tenantId' => $this->tenantId,
            'holder' => $this->holder,
            'purpose' => $this->purpose,
            'acquiredAtMs' => $this->acquiredAtMs,
            'expiresAtMs' => $this->expiresAtMs,
            'schemaVersion' => self::SCHEMA_VERSION,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws RuntimeException If the format is not recognized.
     */
    public static function fromJson(array $data): self
    {
        return match ($data['schemaVersion'] ?? self::SCHEMA_VERSION) {
            self::SCHEMA_VERSION => new self(
                leaseId: (string) $data['leaseId'],
                accessId: (string) $data['accessId'],
                tenantId: (string) $data['tenantId'],
                holder: (string) $data['holder'],
                purpose: (string) $data['purpose'],
                acquiredAtMs: (int) $data['acquiredAtMs'],
                expiresAtMs: (int) $data['expiresAtMs'],
            ),
            default => throw new RuntimeException('Unsupported ProxyLeaseDto schemaVersion: '.var_export($data['schemaVersion'], true)),
        };
    }
}
