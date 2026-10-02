<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Domain\Lease;

use Illuminate\Support\Facades\Cache;

/**
 * Round-robin over candidates with a per-pool cursor persisted in the cache
 * store (survives processes; operational runtime data — Redis, not Postgres).
 */
final readonly class RoundRobinSelectionStrategy implements SelectionStrategy
{
    public function __construct(private string $cursorScope = 'global')
    {
    }

    /**
     * @param  list<ScoredCandidate>  $candidates
     * @return list<ScoredCandidate>
     */
    public function order(array $candidates): array
    {
        $count = count($candidates);

        if ($count < 2) {
            return $candidates;
        }

        $key = 'proxy:selection:cursor:'.$this->cursorScope;
        $cursor = (int) Cache::get($key, 0);
        Cache::put($key, $cursor + $count, now()->addDay());

        $offset = $cursor % $count;

        return array_merge(array_slice($candidates, $offset), array_slice($candidates, 0, $offset));
    }
}
