<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Checker;

/**
 * Per-judge budget configuration for the sliding-window rate limiter.
 */
final readonly class JudgeBudgetConfig
{
    public function __construct(
        public int $rateLimitPerMinute,
        public int $windowSeconds,
    ) {
    }
}
