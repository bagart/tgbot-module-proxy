<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Transport;

use BAGArt\ProxyOperations\Tool\ExecutionPlaneRoute;

/**
 * Handles execution plane requests (plan §11.39 пп.4,18):
 * POST /v1/executions, GET /v1/executions/{id}, POST /v1/executions/{id}/cancel.
 * Delegates to ProbeTool implementations (Stage 4) — this handler is
 * the HTTP API layer, not the execution engine.
 */
final class WorkerExecutionPlaneHandler
{
    /** @var array<string, array{status: string, created: string}> */
    private array $executions = [];

    public function __construct(
        private readonly ResourceGovernor $governor,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{status: string, executionId?: string, error?: string}
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
            'status' => 'pending',
            'created' => date('c'),
        ];

        $this->governor->connectionOpened();

        return ['status' => 'created', 'executionId' => $executionId];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{status: string, execution?: array{status: string, created: string}, error?: string}
     */
    private function getExecution(array $payload): array
    {
        $id = (string) ($payload['executionId'] ?? '');

        if ($id === '' || ! isset($this->executions[$id])) {
            return ['status' => 'error', 'error' => 'Execution not found'];
        }

        return ['status' => 'ok', 'execution' => $this->executions[$id]];
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
}
