<?php

declare(strict_types=1);

use BAGArt\ProxyOperations\Tool\ExecutionPlaneRoute;
use BAGArt\ProxyOperations\Tool\ResourceGovernorSpec;
use BAGArt\ProxyOperations\Transport\ResourceGovernor;
use BAGArt\ProxyOperations\Transport\WorkerExecutionPlaneHandler;

function buildExecHandler(
    ?ResourceGovernor $governor = null,
): WorkerExecutionPlaneHandler {
    $g = $governor ?? new ResourceGovernor(new ResourceGovernorSpec(
        maxConcurrentProbes: 5,
        maxProcesses: 10,
        maxMemoryBytes: 1024 * 1024,
        maxCpuPercent: 80,
        maxExecutionTimeSeconds: 30,
        maxOutputBytes: 1024,
        maxStdinBytes: 1024,
        maxFileDescriptors: 64,
    ));

    return new WorkerExecutionPlaneHandler($g);
}

it('create execution returns created status with id', function (): void {
    $handler = buildExecHandler();
    $result = $handler->handle(ExecutionPlaneRoute::CreateExecution, []);

    expect($result['status'])->toBe('created')
        ->and($result['executionId'])->not->toBeEmpty();
});

it('create execution returns error when governor at capacity', function (): void {
    $governor = new ResourceGovernor(new ResourceGovernorSpec(
        maxConcurrentProbes: 1,
        maxProcesses: 1,
        maxMemoryBytes: 1024,
        maxCpuPercent: 80,
        maxExecutionTimeSeconds: 5,
        maxOutputBytes: 1024,
        maxStdinBytes: 1024,
        maxFileDescriptors: 64,
    ));
    $governor->connectionOpened();

    $handler = buildExecHandler(governor: $governor);
    $result = $handler->handle(ExecutionPlaneRoute::CreateExecution, []);

    expect($result['status'])->toBe('error')
        ->and($result['error'])->toBe('Resource governor at capacity');
});

it('get execution returns execution details', function (): void {
    $handler = buildExecHandler();
    $created = $handler->handle(ExecutionPlaneRoute::CreateExecution, []);
    $result = $handler->handle(ExecutionPlaneRoute::GetExecution, ['executionId' => $created['executionId']]);

    expect($result['status'])->toBe('ok')
        ->and($result['execution']['status'])->toBe('pending');
});

it('get execution returns error for unknown id', function (): void {
    $handler = buildExecHandler();
    $result = $handler->handle(ExecutionPlaneRoute::GetExecution, ['executionId' => 'nonexistent']);

    expect($result['status'])->toBe('error')
        ->and($result['error'])->toBe('Execution not found');
});

it('cancel execution cancels an existing execution', function (): void {
    $handler = buildExecHandler();
    $created = $handler->handle(ExecutionPlaneRoute::CreateExecution, []);
    $result = $handler->handle(ExecutionPlaneRoute::CancelExecution, ['executionId' => $created['executionId']]);

    expect($result['status'])->toBe('cancelled');
});

it('cancel execution returns error for unknown id', function (): void {
    $handler = buildExecHandler();
    $result = $handler->handle(ExecutionPlaneRoute::CancelExecution, ['executionId' => 'nope']);

    expect($result['status'])->toBe('error')
        ->and($result['error'])->toBe('Execution not found');
});

it('executionCount tracks active executions', function (): void {
    $handler = buildExecHandler();

    expect($handler->executionCount())->toBe(0);

    $handler->handle(ExecutionPlaneRoute::CreateExecution, []);
    expect($handler->executionCount())->toBe(1);
});
