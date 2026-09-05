<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Domain\Lease;

/**
 * Ordering policy over scored candidates (plan §5 Phase C). Implementations
 * must not mutate their input and must not perform I/O.
 */
interface SelectionStrategy
{
    /**
     * @param  list<ScoredCandidate>  $candidates
     * @return list<ScoredCandidate> Selection order (best first).
     */
    public function order(array $candidates): array;
}
