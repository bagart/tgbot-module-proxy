<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Checker;

/**
 * Aggregated raw outcomes of one AuditTaskV1 execution (plan §11.30,
 * §11.39 пп.5–6). Pure orchestration result: no domain decisions, no
 * persistence — the normalizer (T21) interprets it afterwards.
 */
final readonly class ProbeExecutionOutcome
{
    /**
     * @param  list<ProbeSingleResult>  $results  One entry per probe execution (judge fan-out included).
     * @param  list<float>  $timingsMs  Wall-clock milliseconds per probe execution, aligned with $results.
     */
    public function __construct(
        public readonly string $taskId,
        public readonly string $attemptId,
        public readonly array $results,
        public readonly array $timingsMs,
        public readonly int $probeCount,
        public readonly int $successCount,
        public readonly int $failureCount,
        public readonly int $executionFailureCount,
    ) {}
}
