<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Domain\Evidence;

use BAGArt\ProxyOperations\Domain\Failure\FailureCode;
use DateTimeImmutable;

/**
 * Raw UDP ASSOCIATE measurement (UDP dimension, plan §11.5).
 *
 * Presence of UDP ASSOCIATE says nothing about DNS behavior — the two are
 * separate dimensions and separate evidence types.
 */
final readonly class UdpEvidence implements DimensionEvidence
{
    public function __construct(
        public bool $associateSucceeded,
        public ?float $roundTripMs,
        public ?FailureCode $failureCode,
        public DateTimeImmutable $measuredAt,
    ) {
    }

    public function type(): EvidenceType
    {
        return EvidenceType::Udp;
    }

    public function measuredAt(): DateTimeImmutable
    {
        return $this->measuredAt;
    }
}
