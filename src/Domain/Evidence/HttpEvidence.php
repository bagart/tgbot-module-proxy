<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Domain\Evidence;

use BAGArt\ProxyOperations\Domain\Failure\FailureCode;
use DateTimeImmutable;

/**
 * Raw HTTP fetch-through-proxy measurement (transport + anonymity dimensions).
 *
 * Header flags are raw allowlisted observation headers (plan §11.7 anonymity
 * fields, R6.4): AnonymityTier is derived later, never stored here.
 */
final readonly class HttpEvidence implements DimensionEvidence
{
    public function __construct(
        public bool $succeeded,
        public ?int $statusCode,
        public ?int $contentLengthBytes,
        /** SHA-256 of the fetched body; full body itself is never cached (R6.4). */
        public ?string $bodyHashSha256,
        public ?float $totalDurationMs,
        public bool $viaHeaderPresent,
        public bool $xffHeaderPresent,
        public bool $forwardedHeaderPresent,
        public bool $xRealIpHeaderPresent,
        public bool $realIpExposed,
        public bool $markerModified,
        public ?FailureCode $failureCode,
        public DateTimeImmutable $measuredAt,
    ) {
    }

    public function type(): EvidenceType
    {
        return EvidenceType::Http;
    }

    public function measuredAt(): DateTimeImmutable
    {
        return $this->measuredAt;
    }
}
