<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Checker;

use BAGArt\ProxyOperations\Domain\Failure\FailureTaxonomy;
use BAGArt\ProxyOperations\Domain\Failure\ProxyFailure;
use BAGArt\ProxyOperations\Wire\AuditResultStatus;
use BAGArt\ProxyOperations\Wire\AuditResultV1;

/**
 * Transforms raw probe outcomes into the wire-level AuditResultV1 (plan
 * §§11.9, 11.16, 11.39 пп.13–14) — the boundary enforcing INV-014/015:
 * checker/platform faults (TOOL_*, REDIS_*, STORAGE_*) become
 * `executionFailures` and never proxy observations; proxy/target/judge/policy
 * failures become `observations`.
 *
 * Status semantics: proxy failures are expected audit outcomes (Completed);
 * only checker-infrastructure faults with no proxy evidence mark the attempt
 * as Failed.
 *
 * Known gap (OD-5, deferred to Stage 5): successful probe data (latency,
 * headers, exit IP) has no AuditResultV1 transport field yet — only aggregate
 * timings travel in `timings`; the success-data transport is decided when the
 * evidence pipeline is built. AuditResultV1's schema is NOT extended here.
 */
final readonly class ExecutionResultNormalizer
{
    public function __construct(
        private readonly FailureTaxonomy $taxonomy,
        private readonly ProbeOutcomeClassifier $classifier,
    ) {}

    public function normalize(
        ProbeExecutionOutcome $outcome,
        string $checkerNodeId,
    ): AuditResultV1 {
        $observations = [];
        $executionFailures = [];

        foreach ($outcome->results as $single) {
            [$classification, $proxyFailure] = $this->classifier->classify($single->toolResult);

            if ($classification === ProbeOutcomeClassification::ProxyFailure) {
                $observations[] = $proxyFailure;
            } elseif ($classification === ProbeOutcomeClassification::ExecutionFailure) {
                $failure = $single->toolResult->failure;

                $executionFailures[] = $failure
                    ?? throw new \LogicException('ExecutionFailure classification without a failure payload.');
            }
        }

        $hasProxyFailures = $observations !== [];
        $hasExecutionFailures = $executionFailures !== [];

        $status = ! $hasProxyFailures && $hasExecutionFailures
            ? AuditResultStatus::Failed
            : AuditResultStatus::Completed;

        return new AuditResultV1(
            taskId: $outcome->taskId,
            attemptId: $outcome->attemptId,
            status: $status,
            observations: $observations,
            executionFailures: $executionFailures,
            timings: $this->aggregateTimings($outcome),
            checkerNodeId: $checkerNodeId,
        );
    }

    /**
     * Aggregate named per-probe tool timings plus the orchestrator's
     * wall-clock total. Keys are sorted for deterministic output.
     *
     * @return array<non-empty-string, int|float>
     */
    private function aggregateTimings(ProbeExecutionOutcome $outcome): array
    {
        $timings = ['totalMs' => array_sum($outcome->timingsMs)];

        foreach ($outcome->results as $single) {
            foreach ($single->toolResult->timingsMs as $name => $ms) {
                $timings[$name] = ($timings[$name] ?? 0) + $ms;
            }
        }

        ksort($timings);

        return $timings;
    }
}
