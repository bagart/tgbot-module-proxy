<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Domain\Evidence;

use BAGArt\ProxyOperations\Domain\Failure\FailureCode;
use DateTimeImmutable;

/**
 * Raw bandwidth transfer measurement (bandwidth dimension).
 *
 * Bandwidth is an orthogonal capability, not a health rank (R6.3): it proves
 * throughput only and never promotes an access on the lifecycle ladder.
 */
final readonly class BandwidthEvidence implements DimensionEvidence
{
    public function __construct(
        public bool $transferCompleted,
        public int $bytesTransferred,
        public float $durationMs,
        public ?FailureCode $failureCode,
        public DateTimeImmutable $measuredAt,
    ) {}

    public function type(): EvidenceType
    {
        return EvidenceType::Bandwidth;
    }

    public function measuredAt(): DateTimeImmutable
    {
        return $this->measuredAt;
    }
}
