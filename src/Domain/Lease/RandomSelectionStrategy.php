<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Domain\Lease;

/**
 * Uniform-random ordering (plan §5 Phase C).
 */
final readonly class RandomSelectionStrategy implements SelectionStrategy
{
    /**
     * @param  list<ScoredCandidate>  $candidates
     * @return list<ScoredCandidate>
     */
    public function order(array $candidates): array
    {
        shuffle($candidates);

        return $candidates;
    }
}
