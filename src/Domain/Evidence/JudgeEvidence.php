<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Domain\Evidence;

use BAGArt\ProxyOperations\Domain\Failure\FailureCode;
use DateTimeImmutable;

/**
 * Raw judge observation for one probe round (judge dimension, plan §11.8).
 *
 * Verdict consistency across judges is a raw fact; trust interpretation
 * (multi-judge agreement policy) happens in tenant interpretation.
 */
final readonly class JudgeEvidence implements DimensionEvidence
{
    public function __construct(
        public string $judgeId,
        public bool $reachable,
        public bool $verdictConsistent,
        public ?float $responseDurationMs,
        public ?FailureCode $failureCode,
        public DateTimeImmutable $measuredAt,
    ) {}

    public function type(): EvidenceType
    {
        return EvidenceType::Judge;
    }

    public function measuredAt(): DateTimeImmutable
    {
        return $this->measuredAt;
    }
}
