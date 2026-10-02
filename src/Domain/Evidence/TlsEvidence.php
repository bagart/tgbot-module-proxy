<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Domain\Evidence;

use BAGArt\ProxyOperations\Domain\Failure\FailureCode;
use DateTimeImmutable;

/**
 * Raw TLS handshake-through-proxy measurement (transport security dimension).
 */
final readonly class TlsEvidence implements DimensionEvidence
{
    public function __construct(
        public bool $handshakeSucceeded,
        public ?string $tlsVersion,
        public ?float $handshakeDurationMs,
        public ?FailureCode $failureCode,
        public DateTimeImmutable $measuredAt,
    ) {
    }

    public function type(): EvidenceType
    {
        return EvidenceType::Tls;
    }

    public function measuredAt(): DateTimeImmutable
    {
        return $this->measuredAt;
    }
}
