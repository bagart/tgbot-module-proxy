<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Checker;

/**
 * Judge rotation strategy per probe profile (plan §11.17):
 * - RoundRobin: standard/light profiles — monotonic counter per (snapshotId, probeType).
 * - All: deep profile — every matching judge.
 * - Random: telegram/DC-focused profile — shuffled subset.
 */
enum JudgeSelectionStrategy: string
{
    case RoundRobin = 'round_robin';
    case All = 'all';
    case Random = 'random';
}
