<?php

declare(strict_types=1);

use BAGArt\ProxyOperations\Domain\Failure\ExecutionFailure;
use BAGArt\ProxyOperations\Domain\Failure\FailureCode;
use BAGArt\ProxyOperations\Domain\Failure\FailureTaxonomy;
use BAGArt\ProxyOperations\Domain\Failure\ProxyFailure;
use BAGArt\ProxyOperations\Wire\AuditResultStatus;
use BAGArt\ProxyOperations\Wire\AuditResultV1;

function resultTaxonomy(): FailureTaxonomy
{
    return new FailureTaxonomy();
}

function wireAuditResult(): AuditResultV1
{
    return new AuditResultV1(
        taskId: 'task-1',
        attemptId: 'attempt-1',
        status: AuditResultStatus::Completed,
        observations: [
            new ProxyFailure(
                resultTaxonomy()->descriptor(FailureCode::TcpTimeout),
                context: ['latencyMs' => 1500],
            ),
            new ProxyFailure(
                resultTaxonomy()->descriptor(FailureCode::AuthFailure),
                context: [],
            ),
        ],
        executionFailures: [
            new ExecutionFailure(
                resultTaxonomy()->descriptor(FailureCode::ToolTimeout),
                context: ['tool' => 'curl'],
            ),
        ],
        timings: ['totalMs' => 5230],
        checkerNodeId: 'checker-node-1',
    );
}

it('round-trips through JSON', function (): void {
    expect(AuditResultV1::fromJson(wireAuditResult()->jsonSerialize()))->toEqual(wireAuditResult());
});

it('rejects an unknown schemaVersion', function (): void {
    $data = wireAuditResult()->jsonSerialize();
    $data['schemaVersion'] = 99;

    AuditResultV1::fromJson($data);
})->throws(RuntimeException::class, 'Unsupported AuditResult schemaVersion');

it('never carries TOOL_* codes as observations (INV-014/015)', function (): void {
    new AuditResultV1(
        taskId: 'task-1',
        attemptId: 'attempt-1',
        status: AuditResultStatus::Failed,
        observations: [
            new ProxyFailure(resultTaxonomy()->descriptor(FailureCode::ToolTimeout)),
        ],
        executionFailures: [],
        timings: [],
        checkerNodeId: 'checker-node-1',
    );
})->throws(InvalidArgumentException::class, 'INV-014/015');

it('never carries REDIS_*/STORAGE_* codes as observations', function (): void {
    new AuditResultV1(
        taskId: 'task-1',
        attemptId: 'attempt-1',
        status: AuditResultStatus::Failed,
        observations: [
            new ProxyFailure(resultTaxonomy()->descriptor(FailureCode::RedisUnavailable)),
        ],
        executionFailures: [],
        timings: [],
        checkerNodeId: 'checker-node-1',
    );
})->throws(InvalidArgumentException::class, 'INV-014/015');

it('accepts TOOL_* codes as execution failures', function (): void {
    foreach ([
        FailureCode::ToolTimeout,
        FailureCode::ToolCrash,
        FailureCode::ToolProtocolError,
        FailureCode::ToolOom,
        FailureCode::ToolExitFailure,
        FailureCode::ToolOutputInvalid,
        FailureCode::ToolUnavailable,
    ] as $code) {
        $result = new AuditResultV1(
            taskId: 'task-1',
            attemptId: 'attempt-1',
            status: AuditResultStatus::TimedOut,
            observations: [],
            executionFailures: [new ExecutionFailure(resultTaxonomy()->descriptor($code))],
            timings: [],
            checkerNodeId: 'checker-node-1',
        );

        expect($result->executionFailures[0]->descriptor->code)->toBe($code);
    }
});

it('rejects proxy-side codes inside the execution-failure collection', function (): void {
    new AuditResultV1(
        taskId: 'task-1',
        attemptId: 'attempt-1',
        status: AuditResultStatus::Failed,
        observations: [],
        executionFailures: [
            new ExecutionFailure(resultTaxonomy()->descriptor(FailureCode::TcpRefused)),
        ],
        timings: [],
        checkerNodeId: 'checker-node-1',
    );
})->throws(InvalidArgumentException::class, 'Checker/Platform');

it('enforces the observation collection type structurally', function (): void {
    new AuditResultV1(
        taskId: 'task-1',
        attemptId: 'attempt-1',
        status: AuditResultStatus::Failed,
        observations: [new stdClass()],
        executionFailures: [],
        timings: [],
        checkerNodeId: 'checker-node-1',
    );
})->throws(InvalidArgumentException::class, 'ProxyFailure instances');

it('rejects an execution-class code among observations on deserialization', function (): void {
    $data = wireAuditResult()->jsonSerialize();
    $data['observations'][] = ['code' => FailureCode::ToolCrash->value, 'context' => []];

    AuditResultV1::fromJson($data);
})->throws(RuntimeException::class, 'INV-014/015');

it('keeps failure context primitives intact on deserialization', function (): void {
    $restored = AuditResultV1::fromJson(wireAuditResult()->jsonSerialize());

    expect($restored->observations[0]->context)->toBe(['latencyMs' => 1500])
        ->and($restored->executionFailures[0]->context)->toBe(['tool' => 'curl'])
        ->and($restored->status)->toBe(AuditResultStatus::Completed)
        ->and($restored->timings)->toBe(['totalMs' => 5230]);
});
