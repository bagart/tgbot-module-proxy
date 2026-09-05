<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Checker;

use BAGArt\ProxyOperations\Domain\Probe\TimeoutTier;

/**
 * Maps a profile TimeoutTier to the per-probe wall-clock budget in
 * milliseconds (plan §11.17). The worker enforces this limit around every
 * tool dispatch, independent of what the tool itself believes.
 */
final readonly class ToolTimeoutFactory
{
    public const int AGGRESSIVE_MS = 5_000;

    public const int STANDARD_MS = 15_000;

    public const int GENEROUS_MS = 30_000;

    /**
     * Convert TimeoutTier enum to milliseconds.
     */
    public function timeoutFor(TimeoutTier $tier): int
    {
        return match ($tier) {
            TimeoutTier::Aggressive => self::AGGRESSIVE_MS,
            TimeoutTier::Standard => self::STANDARD_MS,
            TimeoutTier::Generous => self::GENEROUS_MS,
        };
    }
}
