<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Transport;

use BAGArt\ProxyOperations\Checker\ExecutionResultNormalizer;
use BAGArt\ProxyOperations\Checker\ProbeExecutor;
use BAGArt\ProxyOperations\Domain\Snapshot\JudgeSetSnapshot;
use BAGArt\ProxyOperations\Tool\ExecutionPlaneRoute;
use BAGArt\ProxyOperations\Wire\AuditResultStatus;
use BAGArt\ProxyOperations\Wire\AuditResultV1;
use BAGArt\ProxyOperations\Wire\AuditTaskV1;
use Throwable;

/**
 * Handles execution plane requests (plan §11.39 пп.4,18):
 * POST /v1/executions, GET /v1/executions/{id}, POST /v1/executions/{id}/cancel.
 *
 * W1a evolution: now dispatches to ProbeExecutor → ExecutionResultNormalizer
 * to produce AuditResultV1. Execution is synchronous within the HTTP handler;
 * the tick-loop integration (W1b) will call this from TransportCapabilityDaemon.
 */
final class WorkerExecutionPlaneHandler
{
    /**
     * @var array<string, array{status: string, created: string, result?: AuditResultV1, error?: string}>
     */
    private array $executions = [];

    public function __construct(
        private readonly ResourceGovernor $governor,
        private readonly ProbeExecutor $executor,
        private readonly ExecutionResultNormalizer $normalizer,
        private readonly string $checkerNodeId,
    ) {
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{status: string, executionId?: string, error?: string, result?: array<string,mixed>}
     */
    public function handle(ExecutionPlaneRoute $route, array $payload): array
    {
        return match ($route) {
            ExecutionPlaneRoute::CreateExecution => $this->createExecution($payload),
            ExecutionPlaneRoute::GetExecution => $this->getExecution($payload),
            ExecutionPlaneRoute::CancelExecution => $this->cancelExecution($payload),
        };
    }

    public function executionCount(): int
    {
        return count($this->executions);
    }

    /**
     * Execute a task directly (used by the daemon tick loop).
     *
     * Checks the resource governor, runs the probe, and returns the result.
     * The caller is responsible for governor slot management on error paths.
     *
     * @param  array<string, mixed>  $payload  Keys: task (AuditTaskV1 JSON), judgeSet (optional JudgeSetSnapshot JSON)
     */
    public function processTask(array $payload): AuditResultV1
    {
        if (! $this->governor->canOpenConnection()) {
            throw new \RuntimeException('Resource governor at capacity');
        }

        $this->governor->connectionOpened();

        try {
            return $this->executeTask($payload);
        } finally {
            $this->governor->connectionClosed();
        }
    }

    /**
     * Create and execute an audit task.
     *
     * Payload keys:
     *   - task (array): AuditTaskV1 JSON — if present, probes execute immediately
     *   - judgeSet (array|null): JudgeSetSnapshot JSON for judge-dependent probes
     *
     * @param  array<string, mixed>  $payload
     * @return array{status: string, executionId: string}
     */
    private function createExecution(array $payload): array
    {
        if (! $this->governor->canOpenConnection()) {
            return ['status' => 'error', 'executionId' => '', 'error' => 'Resource governor at capacity'];
        }

        $executionId = bin2hex(random_bytes(16));

        $this->executions[$executionId] = [
            'status' => 'running',
            'created' => date('c'),
        ];

        $this->governor->connectionOpened();

        try {
            if (isset($payload['task'])) {
                $result = $this->executeTask($payload);

                if ($result->status === AuditResultStatus::Failed) {
                    $this->executions[$executionId]['status'] = 'failed';
                    $this->executions[$executionId]['result'] = $result;
                } else {
                    $this->executions[$executionId]['status'] = 'completed';
                    $this->executions[$executionId]['result'] = $result;
                }
            } else {
                $this->executions[$executionId]['status'] = 'pending';
            }
        } catch (Throwable $e) {
            $this->executions[$executionId]['status'] = 'failed';
            $this->executions[$executionId]['error'] = $e->getMessage();
        } finally {
            $this->governor->connectionClosed();
        }

        return ['status' => 'created', 'executionId' => $executionId];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function executeTask(array $payload): AuditResultV1
    {
        $task = AuditTaskV1::fromJson((array) $payload['task']);

        $judgeSet = isset($payload['judgeSet'])
            ? JudgeSetSnapshot::fromJson((array) $payload['judgeSet'])
            : null;

        $startedMs = $this->nowMs();
        $outcome = $this->executor->execute($task, $judgeSet);
        $totalMs = $this->nowMs() - $startedMs;

        $result = $this->normalizer->normalize($outcome, $this->checkerNodeId);

        return new AuditResultV1(
            taskId: $result->taskId,
            attemptId: $result->attemptId,
            status: $result->status,
            observations: $result->observations,
            executionFailures: $result->executionFailures,
            timings: ['totalMs' => $totalMs] + $result->timings,
            checkerNodeId: $result->checkerNodeId,
            accessId: $task->accessId,
            probeData: $result->probeData,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{status: string, execution?: array{status: string, created: string, result?: array<string,mixed>, error?: string}, error?: string}
     */
    private function getExecution(array $payload): array
    {
        $id = (string) ($payload['executionId'] ?? '');

        if ($id === '' || ! isset($this->executions[$id])) {
            return ['status' => 'error', 'error' => 'Execution not found'];
        }

        $entry = $this->executions[$id];
        $response = [
            'status' => 'created' !== '' ? 'ok' : 'ok',
            'execution' => [
                'status' => $entry['status'],
                'created' => $entry['created'],
            ],
        ];

        if (isset($entry['result'])) {
            $response['execution']['result'] = $entry['result']->jsonSerialize();
        }

        if (isset($entry['error'])) {
            $response['execution']['error'] = $entry['error'];
        }

        return $response;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{status: string, error?: string}
     */
    private function cancelExecution(array $payload): array
    {
        $id = (string) ($payload['executionId'] ?? '');

        if ($id === '' || ! isset($this->executions[$id])) {
            return ['status' => 'error', 'error' => 'Execution not found'];
        }

        $this->executions[$id]['status'] = 'cancelled';
        $this->governor->connectionClosed();

        return ['status' => 'cancelled'];
    }

    private function nowMs(): float
    {
        return (float) hrtime(true) / 1_000_000;
    }
}
