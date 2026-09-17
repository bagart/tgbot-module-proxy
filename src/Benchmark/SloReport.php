<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Benchmark;

use JsonSerializable;

/**
 * SLO benchmark report (plan §11.10 п.5).
 * Captures success rate, P50/P95/P99 latency, and error breakdown.
 */
final readonly class SloReport implements JsonSerializable
{
    public function __construct(
        public readonly int $totalChecks,
        public readonly int $successes,
        public readonly int $failures,
        public readonly float $successRate,
        public readonly float $p50LatencyMs,
        public readonly float $p95LatencyMs,
        public readonly float $p99LatencyMs,
        public readonly float $avgLatencyMs,
        public readonly array $errorBreakdown,
        public readonly array $formatBreakdown,
        public readonly int $durationMs,
    ) {}

    /**
     * @param  list<int>  $latencies
     * @param  array<string, int>  $errors
     * @param  array<string, int>  $formats
     */
    public static function fromRaw(
        int $totalChecks,
        int $successes,
        array $latencies,
        array $errors,
        array $formats,
        int $durationMs,
    ): self {
        $failures = $totalChecks - $successes;
        $successRate = $totalChecks > 0 ? ($successes / $totalChecks) * 100 : 0;

        sort($latencies);

        return new self(
            totalChecks: $totalChecks,
            successes: $successes,
            failures: $failures,
            successRate: round($successRate, 2),
            p50LatencyMs: self::percentile($latencies, 50),
            p95LatencyMs: self::percentile($latencies, 95),
            p99LatencyMs: self::percentile($latencies, 99),
            avgLatencyMs: $latencies !== [] ? round(array_sum($latencies) / count($latencies), 2) : 0,
            errorBreakdown: $errors,
            formatBreakdown: $formats,
            durationMs: $durationMs,
        );
    }

    private static function percentile(array $sorted, int $p): float
    {
        if ($sorted === []) {
            return 0;
        }

        $index = (int) ceil(($p / 100) * count($sorted)) - 1;

        return (float) $sorted[max(0, $index)];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'total_checks' => $this->totalChecks,
            'successes' => $this->successes,
            'failures' => $this->failures,
            'success_rate' => $this->successRate,
            'p50_latency_ms' => $this->p50LatencyMs,
            'p95_latency_ms' => $this->p95LatencyMs,
            'p99_latency_ms' => $this->p99LatencyMs,
            'avg_latency_ms' => $this->avgLatencyMs,
            'error_breakdown' => $this->errorBreakdown,
            'format_breakdown' => $this->formatBreakdown,
            'duration_ms' => $this->durationMs,
        ];
    }
}
