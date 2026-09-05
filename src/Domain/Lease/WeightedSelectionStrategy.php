<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Domain\Lease;

/**
 * Highest score first (health × freshness, plan §5 Phase C). Uns scored
 * candidates sink to the end.
 */
final readonly class WeightedSelectionStrategy implements SelectionStrategy
{
    /**
     * @param  list<ScoredCandidate>  $candidates
     * @return list<ScoredCandidate>
     */
    public function order(array $candidates): array
    {
        usort($candidates, static fn (ScoredCandidate $a, ScoredCandidate $b): int => ($b->score ?? -1) <=> ($a->score ?? -1));

        return $candidates;
    }
}
