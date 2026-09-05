<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Domain\Lease;

/**
 * Prefers candidates with the fewest historical leases (least-used, plan §5
 * Phase C). Score breaks ties (higher first).
 */
final readonly class LeastUsedSelectionStrategy implements SelectionStrategy
{
    /**
     * @param  list<ScoredCandidate>  $candidates
     * @return list<ScoredCandidate>
     */
    public function order(array $candidates): array
    {
        usort($candidates, static function (ScoredCandidate $a, ScoredCandidate $b): int {
            $byUse = $a->activeLeases <=> $b->activeLeases;

            if ($byUse !== 0) {
                return $byUse;
            }

            return ($b->score ?? -1) <=> ($a->score ?? -1);
        });

        return $candidates;
    }
}
