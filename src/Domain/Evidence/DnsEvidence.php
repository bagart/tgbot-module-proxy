<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Domain\Evidence;

use BAGArt\ProxyOperations\Domain\Failure\FailureCode;
use DateTimeImmutable;

/**
 * Raw DNS resolution observation through the proxy (DNS dimension, plan §11.5).
 */
final readonly class DnsEvidence implements DimensionEvidence
{
    /**
     * @param  list<string>  $resolvedAddresses  Observed resolution result (raw fact).
     */
    public function __construct(
        public bool $resolutionSucceeded,
        public array $resolvedAddresses,
        public ?float $resolutionDurationMs,
        public ?FailureCode $failureCode,
        public DateTimeImmutable $measuredAt,
    ) {
    }

    public function type(): EvidenceType
    {
        return EvidenceType::Dns;
    }

    public function measuredAt(): DateTimeImmutable
    {
        return $this->measuredAt;
    }
}
