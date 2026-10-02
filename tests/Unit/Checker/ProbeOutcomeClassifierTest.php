<?php

declare(strict_types=1);

use BAGArt\ProxyOperations\Checker\ProbeOutcomeClassification;
use BAGArt\ProxyOperations\Checker\ProbeOutcomeClassifier;
use BAGArt\ProxyOperations\Domain\Failure\ExecutionFailure;
use BAGArt\ProxyOperations\Domain\Failure\FailureCode;
use BAGArt\ProxyOperations\Domain\Failure\FailureTaxonomy;
use BAGArt\ProxyOperations\Domain\Failure\ProxyFailure;
use BAGArt\ProxyOperations\Tool\ProbeToolResult;

beforeEach(function (): void {
    $this->taxonomy = new FailureTaxonomy();
    $this->classifier = new ProbeOutcomeClassifier($this->taxonomy);
});

it('classifies a successful tool result without failure marker as Success', function (): void {
    [$classification, $failure] = $this->classifier->classify(
        ProbeToolResult::ok(['exit_ip' => '1.2.3.4'], ['connectMs' => 15.0]),
    );

    expect($classification)->toBe(ProbeOutcomeClassification::Success)
        ->and($failure)->toBeNull();
});

it('classifies a Checker-class failure as ExecutionFailure', function (): void {
    foreach ([FailureCode::ToolTimeout, FailureCode::ToolCrash, FailureCode::ToolUnavailable] as $code) {
        [$classification, $failure] = $this->classifier->classify(ProbeToolResult::failed(
            new ExecutionFailure($this->taxonomy->descriptor($code), ['k' => 'v']),
            [],
        ));

        expect($classification)->toBe(ProbeOutcomeClassification::ExecutionFailure)
            ->and($failure)->toBeNull();
    }
});

it('classifies a Platform-class failure as ExecutionFailure', function (): void {
    [$classification] = $this->classifier->classify(ProbeToolResult::failed(
        new ExecutionFailure($this->taxonomy->descriptor(FailureCode::RedisUnavailable), []),
        [],
    ));

    expect($classification)->toBe(ProbeOutcomeClassification::ExecutionFailure);
});

it('classifies a Proxy-class failure as ProxyFailure', function (): void {
    [$classification, $failure] = $this->classifier->classify(ProbeToolResult::ok(
        ['failureCode' => FailureCode::TcpRefused->value, 'port' => 1080],
        [],
    ));

    expect($classification)->toBe(ProbeOutcomeClassification::ProxyFailure)
        ->and($failure)->toBeInstanceOf(ProxyFailure::class)
        ->and($failure->descriptor->code)->toBe(FailureCode::TcpRefused)
        ->and($failure->context)->toBe(['port' => 1080]);
});

it('classifies a Target-class failure as ProxyFailure', function (): void {
    [$classification, $failure] = $this->classifier->classify(ProbeToolResult::ok(
        ['failureCode' => FailureCode::Target5xx->value],
        [],
    ));

    expect($classification)->toBe(ProbeOutcomeClassification::ProxyFailure)
        ->and($failure->descriptor->class->value)->toBe('target');
});

it('classifies a Judge-class failure as ProxyFailure', function (): void {
    [$classification, $failure] = $this->classifier->classify(ProbeToolResult::ok(
        ['failureCode' => FailureCode::JudgeUnavailable->value],
        [],
    ));

    expect($classification)->toBe(ProbeOutcomeClassification::ProxyFailure)
        ->and($failure->descriptor->class->value)->toBe('judge');
});

it('classifies a Policy-class failure as ProxyFailure', function (): void {
    [$classification, $failure] = $this->classifier->classify(ProbeToolResult::ok(
        ['failureCode' => FailureCode::SsrfBlocked->value],
        [],
    ));

    expect($classification)->toBe(ProbeOutcomeClassification::ProxyFailure)
        ->and($failure->descriptor->class->value)->toBe('policy');
});

it('accepts a FailureCode instance as the observation marker', function (): void {
    [$classification, $failure] = $this->classifier->classify(ProbeToolResult::ok(
        ['failureCode' => FailureCode::AuthFailure],
        [],
    ));

    expect($classification)->toBe(ProbeOutcomeClassification::ProxyFailure)
        ->and($failure->descriptor->code)->toBe(FailureCode::AuthFailure);
});

it('rejects TOOL_* codes inside observations (INV-014/015)', function (): void {
    $this->classifier->classify(ProbeToolResult::ok(
        ['failureCode' => FailureCode::ToolTimeout->value],
        [],
    ));
})->throws(InvalidArgumentException::class);

it('rejects unknown failure codes inside observations', function (): void {
    $this->classifier->classify(ProbeToolResult::ok(
        ['failureCode' => 'NOT_A_CODE'],
        [],
    ));
})->throws(ValueError::class);

it('is deterministic for repeated classification of the same result', function (): void {
    $result = ProbeToolResult::ok(['failureCode' => FailureCode::TlsFailure->value], ['tlsMs' => 9.0]);

    [$firstClass, $firstFailure] = $this->classifier->classify($result);
    [$secondClass, $secondFailure] = $this->classifier->classify($result);

    expect($firstClass)->toBe($secondClass)
        ->and($secondFailure->descriptor)->toEqual($firstFailure->descriptor)
        ->and($secondFailure->context)->toBe($firstFailure->context);
});
