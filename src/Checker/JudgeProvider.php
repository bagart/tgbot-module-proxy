<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Checker;

use BAGArt\ProxyOperations\Domain\Probe\ProbeType;
use BAGArt\ProxyOperations\Domain\Snapshot\JudgeDescriptor;
use BAGArt\ProxyOperations\Domain\Snapshot\JudgeSetSnapshot;

/**
 * Resolves judges from an immutable snapshot for a given probe type
 * (plan §§11.8, 11.17, 11.35 п.8).
 */
interface JudgeProvider
{
    /**
     * Select judges for a probe type from the given snapshot.
     *
     * @return list<JudgeDescriptor> Judges eligible for the requested probe type, or empty when unavailable.
     */
    public function select(
        JudgeSetSnapshot $snapshot,
        ProbeType $probeType,
        int $count,
    ): array;
}
