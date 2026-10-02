<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Domain\Lease;

/**
 * One scored candidate flowing through a selection strategy. Score is an
 * operational ordering metric (health × freshness), not tenant
 * interpretation — it is logged for explainability only.
 */
final readonly class ScoredCandidate
{
    public function __construct(
        public readonly string $accessId,
        public readonly ?float $score,
        public readonly int $activeLeases, // least-used signal (historical count)
    ) {
    }
}
