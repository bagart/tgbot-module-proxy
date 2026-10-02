<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Audit;

use JsonSerializable;

/**
 * Dead-letter entry for an audit attempt whose delivery retry budget is
 * exhausted (plan §11.27, §11.37 R6.5). Carries identity and routing metadata
 * only — tenant_id labels DLQ routing, never authz (§11.30). Contains no
 * credential material by construction.
 */
final readonly class AuditDeadLetter implements JsonSerializable
{
    public const int SCHEMA_VERSION = 1;

    public function __construct(
        public readonly string $jobId,
        public readonly string $attemptId,
        public readonly int $tenantId,
        public readonly string $reason,
        public readonly string $deadLetteredAt, // ISO 8601 instant.
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'jobId' => $this->jobId,
            'attemptId' => $this->attemptId,
            'tenantId' => $this->tenantId,
            'reason' => $this->reason,
            'deadLetteredAt' => $this->deadLetteredAt,
            'schemaVersion' => self::SCHEMA_VERSION,
        ];
    }
}
