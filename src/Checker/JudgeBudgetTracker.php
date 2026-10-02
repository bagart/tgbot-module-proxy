<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Checker;

/**
 * In-memory sliding-window rate limiter per judge (plan INV-009).
 * Worker-scoped — no Redis needed for MVP (OD-6).
 */
final class JudgeBudgetTracker
{
    /** @var array<string, list<int>>  judgeId → list of request timestamps (seconds) */
    private array $windows = [];

    public function __construct(
        private readonly JudgeBudgetConfig $config,
    ) {
    }

    public function allow(string $judgeId): bool
    {
        $now = time();
        $cutoff = $now - $this->config->windowSeconds;

        $this->windows[$judgeId] = array_values(array_filter(
            $this->windows[$judgeId] ?? [],
            static fn (int $ts): bool => $ts > $cutoff,
        ));

        if (count($this->windows[$judgeId]) >= $this->config->rateLimitPerMinute) {
            return false;
        }

        $this->windows[$judgeId][] = $now;

        return true;
    }

    public function remaining(string $judgeId): int
    {
        $now = time();
        $cutoff = $now - $this->config->windowSeconds;

        $this->windows[$judgeId] = array_values(array_filter(
            $this->windows[$judgeId] ?? [],
            static fn (int $ts): bool => $ts > $cutoff,
        ));

        return max(0, $this->config->rateLimitPerMinute - count($this->windows[$judgeId]));
    }
}
