<?php

declare(strict_types=1);

use BAGArt\ProxyOperations\Checker\ExecutionResultNormalizer;
use BAGArt\ProxyOperations\Checker\ProbeExecutionOutcome;
use BAGArt\ProxyOperations\Checker\ProbeOutcomeClassification;
use BAGArt\ProxyOperations\Checker\ProbeOutcomeClassifier;
use BAGArt\ProxyOperations\Checker\ProbeSingleResult;
use BAGArt\ProxyOperations\Domain\Failure\ExecutionFailure;
use BAGArt\ProxyOperations\Domain\Failure\FailureCode;
use BAGArt\ProxyOperations\Domain\Failure\FailureTaxonomy;
use BAGArt\ProxyOperations\Domain\Failure\ProxyFailure;
use BAGArt\ProxyOperations\Domain\Probe\ProbeType;
use BAGArt\ProxyOperations\Tool\ProbeToolResult;
use BAGArt\ProxyOperations\Wire\AuditResultStatus;

beforeEach(function (): void {
    $this->taxonomy = new FailureTaxonomy();
    $this->classifier = new ProbeOutcomeClassifier($this->taxonomy);
    $this->normalizer = new ExecutionResultNormalizer($this->taxonomy, $this->classifier);
});

function singleResult(ProbeToolResult $toolResult, ProbeType $probeType = ProbeType::LatencySeries, ?string $judgeId = null): ProbeSingleResult
{
    return new ProbeSingleResult(probeType: $probeType, judgeId: $judgeId, toolResult: $toolResult);
}

function outcome(array $results, array $timingsMs = []): ProbeExecutionOutcome
{
    $timingsMs = $timingsMs !== [] ? $timingsMs : array_fill(0, count($results), 10.0);

    return new ProbeExecutionOutcome(
        taskId: 'task-1',
        attemptId: 'attempt-1',
        results: $results,
        timingsMs: $timingsMs,
        probeCount: count($results),
        successCount: 0,
        failureCount: 0,
        executionFailureCount: 0,
    );
}

it('maps all-successful probes to Completed with empty observations and executionFailures', function (): void {
    $raw = outcome([
        singleResult(ProbeToolResult::ok(['exit_ip' => '1.2.3.4'], ['connectMs' => 12.5])),
        singleResult(ProbeToolResult::ok([], ['connectMs' => 8.0])),
    ]);

    $result = $this->normalizer->normalize($raw, 'checker-node-1');

    expect($result->status)->toBe(AuditResultStatus::Completed)
        ->and($result->observations)->toBe([])
        ->and($result->executionFailures)->toBe([])
        ->and($result->taskId)->toBe('task-1')
        ->and($result->attemptId)->toBe('attempt-1');
});

it('classifies a proxy failure from observations and keeps status Completed', function (): void {
    $raw = outcome([
        singleResult(ProbeToolResult::ok(
            ['failureCode' => FailureCode::TcpTimeout->value, 'elapsedMs' => 5_000],
            ['connectMs' => 5_000.0],
        )),
    ]);

    $result = $this->normalizer->normalize($raw, 'checker-node-1');

    expect($result->status)->toBe(AuditResultStatus::Completed)
        ->and($result->observations)->toHaveCount(1)
        ->and($result->observations[0])->toBeInstanceOf(ProxyFailure::class)
        ->and($result->observations[0]->descriptor->code)->toBe(FailureCode::TcpTimeout)
        ->and($result->observations[0]->context)->toBe(['elapsedMs' => 5_000])
        ->and($result->executionFailures)->toBe([]);
});

it('maps a tool timeout to an ExecutionFailure with status Failed', function (): void {
    $raw = outcome([
        singleResult(ProbeToolResult::failed(
            new ExecutionFailure($this->taxonomy->descriptor(FailureCode::ToolTimeout), ['limitMs' => 15_000]),
            [],
        )),
    ]);

    $result = $this->normalizer->normalize($raw, 'checker-node-1');

    expect($result->status)->toBe(AuditResultStatus::Failed)
        ->and($result->executionFailures)->toHaveCount(1)
        ->and($result->executionFailures[0]->descriptor->code)->toBe(FailureCode::ToolTimeout)
        ->and($result->observations)->toBe([]);
});

it('aggregates mixed proxy and execution failures into Completed with both lists populated', function (): void {
    $raw = outcome([
        singleResult(ProbeToolResult::ok(
            ['failureCode' => FailureCode::AuthFailure->value],
            ['handshakeMs' => 100.0],
        ), ProbeType::HttpLiveness, 'judge-1'),
        singleResult(ProbeToolResult::failed(
            new ExecutionFailure($this->taxonomy->descriptor(FailureCode::ToolCrash), ['message' => 'segfault']),
            [],
        )),
        singleResult(ProbeToolResult::ok(['exit_ip' => '5.6.7.8'], ['connectMs' => 42.0])),
    ]);

    $result = $this->normalizer->normalize($raw, 'checker-node-1');

    expect($result->status)->toBe(AuditResultStatus::Completed)
        ->and($result->observations)->toHaveCount(1)
        ->and($result->observations[0]->descriptor->code)->toBe(FailureCode::AuthFailure)
        ->and($result->executionFailures)->toHaveCount(1)
        ->and($result->executionFailures[0]->descriptor->code)->toBe(FailureCode::ToolCrash);
});

it('normalizes an empty outcome to a zero-result Completed AuditResultV1', function (): void {
    $result = $this->normalizer->normalize(outcome([]), 'checker-node-1');

    expect($result->status)->toBe(AuditResultStatus::Completed)
        ->and($result->observations)->toBe([])
        ->and($result->executionFailures)->toBe([])
        ->and($result->timings)->toBe(['totalMs' => 0]);
});

it('propagates the checkerNodeId', function (): void {
    $result = $this->normalizer->normalize(outcome([]), 'checker-node-7');

    expect($result->checkerNodeId)->toBe('checker-node-7');
});

it('aggregates timings deterministically from per-probe values', function (): void {
    $raw = outcome([
        singleResult(ProbeToolResult::ok([], ['connectMs' => 10.0])),
        singleResult(ProbeToolResult::ok([], ['connectMs' => 20.0, 'tlsMs' => 5.0])),
    ], [100.0, 200.0]);

    $result = $this->normalizer->normalize($raw, 'checker-node-1');

    expect($result->timings['totalMs'])->toBe(300.0)
        ->and($result->timings['connectMs'])->toBe(30.0)
        ->and($result->timings['tlsMs'])->toBe(5.0)
        ->and(array_keys($result->timings))->toBe(['connectMs', 'tlsMs', 'totalMs']);
});

it('enforces INV-014: TOOL_* codes never appear in observations', function (): void {
    $raw = outcome([
        singleResult(ProbeToolResult::failed(
            new ExecutionFailure($this->taxonomy->descriptor(FailureCode::ToolUnavailable), []),
            [],
        )),
        singleResult(ProbeToolResult::ok(
            ['failureCode' => FailureCode::TcpRefused->value],
            [],
        )),
    ]);

    $result = $this->normalizer->normalize($raw, 'checker-node-1');

    $observationCodes = array_map(
        static fn ($o) => $o->descriptor->code,
        $result->observations,
    );

    expect($observationCodes)->each->toBe(FailureCode::TcpRefused)
        ->and($observationCodes)->not->toContain(FailureCode::ToolUnavailable)
        ->and($observationCodes)->not->toContain(FailureCode::ToolTimeout)
        ->and($observationCodes)->not->toContain(FailureCode::ToolCrash)
        ->and($result->executionFailures[0]->descriptor->code)->toBe(FailureCode::ToolUnavailable);
});

it('enforces INV-015: proxy-side codes never appear in executionFailures', function (): void {
    $raw = outcome([
        singleResult(ProbeToolResult::ok(
            ['failureCode' => FailureCode::TcpTimeout->value],
            [],
        )),
    ]);

    $result = $this->normalizer->normalize($raw, 'checker-node-1');

    expect($result->executionFailures)->toBe([])
        ->and($result->observations[0]->descriptor->code)->toBe(FailureCode::TcpTimeout);
});

it('rejects execution-class codes smuggled inside observations', function (): void {
    $raw = outcome([
        singleResult(ProbeToolResult::ok(
            ['failureCode' => FailureCode::ToolTimeout->value],
            [],
        )),
    ]);

    $this->normalizer->normalize($raw, 'checker-node-1');
})->throws(InvalidArgumentException::class);

it('resolves failure descriptors via the FailureTaxonomy', function (): void {
    $raw = outcome([
        singleResult(ProbeToolResult::ok(
            ['failureCode' => FailureCode::MtprotoHandshakeFailed->value],
            [],
        )),
    ]);

    $result = $this->normalizer->normalize($raw, 'checker-node-1');
    $descriptor = $result->observations[0]->descriptor;

    expect($descriptor)->toEqual($this->taxonomy->descriptor(FailureCode::MtprotoHandshakeFailed))
        ->and($descriptor->class->value)->toBe('proxy')
        ->and($descriptor->retryable)->toBeTrue();
});

it('produces identical output for identical input (determinism)', function (): void {
    $build = fn (): ProbeExecutionOutcome => outcome([
        singleResult(ProbeToolResult::ok(
            ['failureCode' => FailureCode::TlsFailure->value, 'exit_ip' => '9.9.9.9'],
            ['connectMs' => 11.0],
        )),
        singleResult(ProbeToolResult::failed(
            new ExecutionFailure($this->taxonomy->descriptor(FailureCode::RedisUnavailable), []),
            [],
        ), ProbeType::DnsResolution),
    ], [50.0, 60.0]);

    $first = $this->normalizer->normalize($build(), 'checker-node-1');
    $second = $this->normalizer->normalize($build(), 'checker-node-1');

    expect($second->jsonSerialize())->toBe($first->jsonSerialize())
        ->and($first->status)->toBe(AuditResultStatus::Completed);
});

it('classifies outcomes through the ProbeOutcomeClassification enum', function (): void {
    [$success] = $this->classifier->classify(ProbeToolResult::ok(['exit_ip' => '1.1.1.1'], []));
    [$proxy] = $this->classifier->classify(ProbeToolResult::ok(['failureCode' => FailureCode::TcpRefused->value], []));
    [$execution] = $this->classifier->classify(ProbeToolResult::failed(
        new ExecutionFailure($this->taxonomy->descriptor(FailureCode::ToolOom), []),
        [],
    ));

    expect($success)->toBe(ProbeOutcomeClassification::Success)
        ->and($proxy)->toBe(ProbeOutcomeClassification::ProxyFailure)
        ->and($execution)->toBe(ProbeOutcomeClassification::ExecutionFailure);
});
