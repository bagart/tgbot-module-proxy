<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Domain\Evidence;

use BAGArt\ProxyOperations\Domain\Failure\FailureCode;
use DateTimeImmutable;

/**
 * Raw TCP reachability measurement of the proxy endpoint (liveness dimension).
 */
final readonly class TcpEvidence implements DimensionEvidence
{
    public function __construct(
        public bool $connectSucceeded,
        public ?int $latencyMs,
        public ?FailureCode $failureCode,
        public DateTimeImmutable $measuredAt,
    ) {}

    public function type(): EvidenceType
    {
        return EvidenceType::Tcp;
    }

    public function measuredAt(): DateTimeImmutable
    {
        return $this->measuredAt;
    }
}
