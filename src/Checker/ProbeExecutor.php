<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Checker;

use BAGArt\ProxyOperations\Domain\Failure\ExecutionFailure;
use BAGArt\ProxyOperations\Domain\Failure\FailureCode;
use BAGArt\ProxyOperations\Domain\Failure\FailureTaxonomy;
use BAGArt\ProxyOperations\Domain\Probe\ProbeProfileDefinition;
use BAGArt\ProxyOperations\Domain\Probe\ProbeType;
use BAGArt\ProxyOperations\Domain\Snapshot\JudgeSetSnapshot;
use BAGArt\ProxyOperations\Transport\ProbeContextBuilder;
use BAGArt\ProxyOperations\Transport\ResourceGovernor;
use BAGArt\ProxyOperations\Wire\AuditTaskV1;
use BAGArt\ProxyOperations\Wire\ProbeExecutionSpecV1;
use BAGArt\ProxyOperations\Tool\ProbeTool;
use BAGArt\ProxyOperations\Tool\ProbeToolResult;
use BAGArt\ProxyOperations\Tool\ToolRegistry;
use Throwable;

/**
 * Central probe orchestrator (plan §§11.30, 11.39 пп.5–6,8,15): receives an
 * AuditTaskV1, plans the concrete probe executions (judge fan-out included),
 * enforces the Resource Governor before every dispatch (INV-019), dispatches
 * to registered ProbeTool implementations and collects raw ProbeToolResult
 * outcomes. Orchestration only — no domain decisions, no Postgres writes
 * (INV-003/009); domain entities never cross the tool boundary (INV-011).
 */
final class ProbeExecutor
{
    /**
     * Judge-dependent probe types: each selected judge yields one separate
     * probe execution against the judge URL (plan §11.39 п.6).
     */
    private const array JUDGE_DEPENDENT_PROBES = [
        ProbeType::HttpLiveness,
        ProbeType::HeaderMarker,
        ProbeType::AnonymityHeaders,
    ];

    private const int JUDGES_PER_PROBE = 1;

    /** Sentinel judge id marking "no judge could be selected". */
    private const string NO_JUDGE = "\0no-judge";

    private readonly ProbeContextBuilder $contextBuilder;

    private readonly FailureTaxonomy $taxonomy;

    /**
     * @param  array<non-empty-string, ProbeTool>  $tools  ProbeTool implementations keyed by ToolId value.
     * @param  JudgeProvider  $judgeProvider  Judge resolution for judge-dependent probes.
     * @param  ToolRegistry  $toolRegistry  Allowlist manifests; the ONLY resolution path to a tool (INV-012).
     * @param  ResourceGovernor  $governor  In-process budget checked before every probe (INV-019).
     * @param  ToolTimeoutFactory  $timeoutFactory  Timeout-tier wall-clock budgets (plan §11.17).
     */
    public function __construct(
        private readonly array $tools,
        private readonly JudgeProvider $judgeProvider,
        private readonly ToolRegistry $toolRegistry,
        private readonly ResourceGovernor $governor,
        private readonly ToolTimeoutFactory $timeoutFactory,
    ) {
        $this->contextBuilder = new ProbeContextBuilder;
        $this->taxonomy = new FailureTaxonomy;
    }

    /**
     * Execute every probe of the task and collect raw outcomes.
     *
     * @param  JudgeSetSnapshot|null  $judgeSet  Frozen judge set for judge-dependent probes; their
     *                                        snapshots travel with the audit job (plan §11.35 п.8).
     */
    public function execute(AuditTaskV1 $task, ?JudgeSetSnapshot $judgeSet = null): ProbeExecutionOutcome
    {
        $results = [];
        $timingsMs = [];

        foreach ($task->probes as $probe) {
            foreach ($this->runProbe($task, $probe, $judgeSet) as [$result, $elapsedMs]) {
                $results[] = $result;
                $timingsMs[] = $elapsedMs;
            }
        }

        return new ProbeExecutionOutcome(
            taskId: $task->job->taskId,
            attemptId: $task->job->attemptId,
            results: $results,
            timingsMs: $timingsMs,
            probeCount: count($results),
            successCount: count(array_filter($results, static fn (ProbeSingleResult $r): bool => $r->toolResult->ok)),
            failureCount: count(array_filter(
                $results,
                static fn (ProbeSingleResult $r): bool => ! $r->toolResult->ok && $r->toolResult->failure === null,
            )),
            executionFailureCount: count(array_filter(
                $results,
                static fn (ProbeSingleResult $r): bool => $r->toolResult->failure instanceof ExecutionFailure,
            )),
        );
    }

    /**
     * Run one probe spec; yields [ProbeSingleResult, elapsedMs] tuples
     * (multiple for judge fan-out, none-empty for skipped/failed planning).
     *
     * @return list<array{0: ProbeSingleResult, 1: float}>
     */
    private function runProbe(AuditTaskV1 $task, ProbeExecutionSpecV1 $probe, ?JudgeSetSnapshot $judgeSet): array
    {
        // INV-019: governor checked before every probe dispatch.
        if (! $this->governor->canOpenConnection()) {
            return [$this->executionFailure(
                $probe,
                null,
                FailureCode::ToolUnavailable,
                ['reason' => 'governor_at_capacity'],
            )];
        }

        $tool = $this->resolveTool($probe->probeType);

        if ($tool === null) {
            return [$this->executionFailure(
                $probe,
                null,
                FailureCode::ToolUnavailable,
                ['reason' => 'tool_not_found', 'probeType' => $probe->probeType->value],
            )];
        }

        $targets = $this->resolveTargets($probe, $judgeSet);
        $outcomes = [];

        foreach ($targets as [$target, $judgeId]) {
            $outcomes[] = $judgeId === self::NO_JUDGE
                ? $this->executionFailure(
                    $probe,
                    null,
                    FailureCode::ToolUnavailable,
                    ['reason' => 'no_judge_available', 'probeType' => $probe->probeType->value],
                )
                : $this->dispatch($task, $probe, $tool, $target, $judgeId);
        }

        return $outcomes;
    }

    /**
     * Resolve a probe target list: the spec target itself, or one entry per
     * selected judge (target = judge URL) for judge-dependent probes.
     *
     * @return list<array{0: string, 1: ?string}>
     */
    private function resolveTargets(ProbeExecutionSpecV1 $probe, ?JudgeSetSnapshot $judgeSet): array
    {
        if (! in_array($probe->probeType, self::JUDGE_DEPENDENT_PROBES, strict: true)) {
            return [[$probe->target, null]];
        }

        $judges = $judgeSet === null
            ? []
            : $this->judgeProvider->select($judgeSet, $probe->probeType, self::JUDGES_PER_PROBE);

        if ($judges === []) {
            // No judge available is a checker-side provisioning fault, not a
            // proxy observation (INV-014/015) — surfaced as ToolUnavailable
            // by the caller so the failure is recorded, never swallowed.
            return [['', self::NO_JUDGE]];
        }

        return array_map(
            static fn ($judge): array => [$judge->url, $judge->id],
            $judges,
        );
    }

    /**
     * Dispatch a single probe execution under governor + timeout control.
     *
     * @return array{0: ProbeSingleResult, 1: float}
     */
    private function dispatch(
        AuditTaskV1 $task,
        ProbeExecutionSpecV1 $probe,
        ProbeTool $tool,
        string $target,
        ?string $judgeId,
    ): array {
        $spec = $probe->target === $target
            ? $probe
            : new ProbeExecutionSpecV1(
                probeType: $probe->probeType,
                profile: $probe->profile,
                target: $target,
                timeoutMs: $probe->timeoutMs,
                maxOutputBytes: $probe->maxOutputBytes,
            );

        $context = $this->contextBuilder->fromAuditTask($task, $spec);
        $startedMs = $this->nowMs();

        $this->governor->connectionOpened();

        try {
            $result = $tool->execute($context);
        } catch (Throwable $e) {
            // INV-014/015: a tool crash is an ExecutionFailure, never a proxy
            // observation. Context carries class/message only — no secrets.
            return $this->executionFailure(
                $probe,
                $judgeId,
                FailureCode::ToolCrash,
                [
                    'exception_class' => $e::class,
                    'message' => $e->getMessage(),
                    'elapsedMs' => $this->nowMs() - $startedMs,
                ],
            );
        } finally {
            $this->governor->connectionClosed();
        }

        $elapsedMs = $this->nowMs() - $startedMs;

        // Wall-clock timeout enforcement by the worker (plan §11.39 п.15):
        // the stricter of the spec budget and the profile timeout tier.
        $limitMs = min($probe->timeoutMs, $this->profileBudgetMs($probe));

        if ($elapsedMs > $limitMs) {
            return $this->executionFailure(
                $probe,
                $judgeId,
                FailureCode::ToolTimeout,
                ['limitMs' => $limitMs, 'elapsedMs' => $elapsedMs],
            );
        }

        return [
            new ProbeSingleResult(
                probeType: $probe->probeType,
                judgeId: $judgeId,
                toolResult: $result,
            ),
            $elapsedMs,
        ];
    }

    /**
     * Wall-clock budget derived from the probe profile's timeout tier.
     */
    private function profileBudgetMs(ProbeExecutionSpecV1 $probe): int
    {
        return $this->timeoutFactory->timeoutFor(
            ProbeProfileDefinition::forProfile($probe->profile)->timeout,
        );
    }

    /**
     * Find the allowlisted tool whose capabilities cover the probe type
     * (plan §11.39 п.10) and return its bound implementation, if any.
     */
    private function resolveTool(ProbeType $probeType): ?ProbeTool
    {
        foreach ($this->toolRegistry->manifests() as $manifest) {
            if (in_array($probeType, $manifest->capabilities->probeTypes, strict: true)) {
                return $this->tools[$manifest->name->value] ?? null;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{0: ProbeSingleResult, 1: float}
     */
    private function executionFailure(
        ProbeExecutionSpecV1 $probe,
        ?string $judgeId,
        FailureCode $code,
        array $context,
    ): array {
        $failure = new ExecutionFailure($this->taxonomy->descriptor($code), $context);

        return [
            new ProbeSingleResult(
                probeType: $probe->probeType,
                judgeId: $judgeId,
                toolResult: ProbeToolResult::failed($failure, []),
            ),
            0.0,
        ];
    }

    private function nowMs(): float
    {
        return (float) hrtime(true) / 1_000_000;
    }
}
