<?php

declare(strict_types=1);

use BAGArt\ProxyOperations\Domain\Failure\ExecutionFailure;
use BAGArt\ProxyOperations\Domain\Failure\FailureClass;
use BAGArt\ProxyOperations\Domain\Failure\FailureCode;
use BAGArt\ProxyOperations\Domain\Failure\FailureDescriptor;
use BAGArt\ProxyOperations\Domain\Failure\FailureTaxonomy;
use BAGArt\ProxyOperations\Domain\Failure\ProxyFailure;

beforeEach(fn (): object => $this->taxonomy = new FailureTaxonomy());

it('has a descriptor with all attributes for every failure code', function (): void {
    foreach (FailureCode::cases() as $code) {
        $descriptor = $this->taxonomy->descriptor($code);

        expect($descriptor)->toBeInstanceOf(FailureDescriptor::class)
            ->and($descriptor->code)->toBe($code)
            ->and($descriptor->class)->toBeInstanceOf(FailureClass::class)
            ->and($descriptor->retryable)->toBeBool()
            ->and($descriptor->countsAsFailure)->toBeBool()
            ->and($descriptor->affectsHealth)->toBeBool()
            ->and($descriptor->affectsCapability)->toBeBool()
            ->and($descriptor->quarantineAfterThreshold)->toBeBool();
    }
});

it('assigns the responsibility axis of plan §11.16 to every code', function (FailureCode $code, FailureClass $class): void {
    expect($this->taxonomy->descriptor($code)->class)->toBe($class);
})->with([
    'TCP_TIMEOUT' => [FailureCode::TcpTimeout, FailureClass::Proxy],
    'TCP_REFUSED' => [FailureCode::TcpRefused, FailureClass::Proxy],
    'AUTH_FAILURE' => [FailureCode::AuthFailure, FailureClass::Proxy],
    'TLS_FAILURE' => [FailureCode::TlsFailure, FailureClass::Proxy],
    'MTPROTO_HANDSHAKE_FAILED' => [FailureCode::MtprotoHandshakeFailed, FailureClass::Proxy],
    'TARGET_4XX' => [FailureCode::Target4xx, FailureClass::Target],
    'TARGET_5XX' => [FailureCode::Target5xx, FailureClass::Target],
    'JUDGE_UNAVAILABLE' => [FailureCode::JudgeUnavailable, FailureClass::Judge],
    'JUDGE_INCONSISTENT' => [FailureCode::JudgeInconsistent, FailureClass::Judge],
    'TOOL_TIMEOUT' => [FailureCode::ToolTimeout, FailureClass::Checker],
    'TOOL_CRASH' => [FailureCode::ToolCrash, FailureClass::Checker],
    'TOOL_PROTOCOL_ERROR' => [FailureCode::ToolProtocolError, FailureClass::Checker],
    'TOOL_OOM' => [FailureCode::ToolOom, FailureClass::Checker],
    'TOOL_EXIT_FAILURE' => [FailureCode::ToolExitFailure, FailureClass::Checker],
    'TOOL_OUTPUT_INVALID' => [FailureCode::ToolOutputInvalid, FailureClass::Checker],
    'TOOL_UNAVAILABLE' => [FailureCode::ToolUnavailable, FailureClass::Checker],
    'REDIS_UNAVAILABLE' => [FailureCode::RedisUnavailable, FailureClass::Platform],
    'STORAGE_UNAVAILABLE' => [FailureCode::StorageUnavailable, FailureClass::Platform],
    'SSRF_BLOCKED' => [FailureCode::SsrfBlocked, FailureClass::Policy],
    'UNSUPPORTED_PROTOCOL' => [FailureCode::UnsupportedProtocol, FailureClass::Policy],
]);

it('never lets checker tool faults count as proxy failures', function (FailureCode $code): void {
    $descriptor = $this->taxonomy->descriptor($code);

    expect($descriptor->class)->toBe(FailureClass::Checker)
        ->and($descriptor->affectsHealth)->toBeFalse()
        ->and($descriptor->countsAsFailure)->toBeFalse()
        ->and($descriptor->affectsCapability)->toBeFalse()
        ->and($descriptor->quarantineAfterThreshold)->toBeFalse();
})->with(collect(FailureCode::cases())->filter(fn ($c) => str_starts_with($c->value, 'TOOL_'))->values()->all());

it('never lets platform faults count as proxy failures', function (FailureCode $code): void {
    $descriptor = $this->taxonomy->descriptor($code);

    expect($descriptor->class)->toBe(FailureClass::Platform)
        ->and($descriptor->affectsHealth)->toBeFalse()
        ->and($descriptor->countsAsFailure)->toBeFalse()
        ->and($descriptor->affectsCapability)->toBeFalse()
        ->and($descriptor->quarantineAfterThreshold)->toBeFalse();
})->with([FailureCode::RedisUnavailable, FailureCode::StorageUnavailable]);

it('keeps target errors off proxy capability and counting', function (): void {
    foreach ([FailureCode::Target4xx, FailureCode::Target5xx] as $code) {
        $descriptor = $this->taxonomy->descriptor($code);

        expect($descriptor->affectsCapability)->toBeFalse()
            ->and($descriptor->countsAsFailure)->toBeFalse();
    }
});

it('softly affects health for target errors while still retrying 5xx', function (): void {
    $target4xx = $this->taxonomy->descriptor(FailureCode::Target4xx);
    $target5xx = $this->taxonomy->descriptor(FailureCode::Target5xx);

    expect($target4xx->affectsHealth)->toBeTrue()
        ->and($target4xx->retryable)->toBeFalse()
        ->and($target5xx->affectsHealth)->toBeTrue()
        ->and($target5xx->retryable)->toBeTrue();
});

it('matches plan §11.16 attribute rows', function (FailureCode $code, array $attributes): void {
    $descriptor = $this->taxonomy->descriptor($code);

    expect([$descriptor->retryable, $descriptor->countsAsFailure, $descriptor->affectsHealth, $descriptor->affectsCapability, $descriptor->quarantineAfterThreshold])
        ->toEqual($attributes);
})->with([
    'TCP_TIMEOUT' => [FailureCode::TcpTimeout, [true, true, true, false, true]],
    'TCP_REFUSED' => [FailureCode::TcpRefused, [true, true, true, false, true]],
    'AUTH_FAILURE' => [FailureCode::AuthFailure, [false, true, true, true, true]],
    'TLS_FAILURE' => [FailureCode::TlsFailure, [true, true, true, true, false]],
    'MTPROTO_HANDSHAKE_FAILED' => [FailureCode::MtprotoHandshakeFailed, [true, true, true, true, false]],
    'TARGET_4XX' => [FailureCode::Target4xx, [false, false, true, false, false]],
    'TARGET_5XX' => [FailureCode::Target5xx, [true, false, true, false, false]],
    'JUDGE_UNAVAILABLE' => [FailureCode::JudgeUnavailable, [true, false, false, false, false]],
    'JUDGE_INCONSISTENT' => [FailureCode::JudgeInconsistent, [true, false, false, true, false]],
    'TOOL_TIMEOUT' => [FailureCode::ToolTimeout, [true, false, false, false, false]],
    'TOOL_CRASH' => [FailureCode::ToolCrash, [true, false, false, false, false]],
    'TOOL_PROTOCOL_ERROR' => [FailureCode::ToolProtocolError, [true, false, false, false, false]],
    'TOOL_OOM' => [FailureCode::ToolOom, [true, false, false, false, false]],
    'TOOL_EXIT_FAILURE' => [FailureCode::ToolExitFailure, [true, false, false, false, false]],
    'TOOL_OUTPUT_INVALID' => [FailureCode::ToolOutputInvalid, [true, false, false, false, false]],
    'TOOL_UNAVAILABLE' => [FailureCode::ToolUnavailable, [true, false, false, false, false]],
    'REDIS_UNAVAILABLE' => [FailureCode::RedisUnavailable, [true, false, false, false, false]],
    'STORAGE_UNAVAILABLE' => [FailureCode::StorageUnavailable, [true, false, false, false, false]],
    'SSRF_BLOCKED' => [FailureCode::SsrfBlocked, [false, false, false, false, true]],
    'UNSUPPORTED_PROTOCOL' => [FailureCode::UnsupportedProtocol, [false, false, false, true, false]],
]);

it('types checker and platform faults as execution failures', function (FailureCode $code): void {
    $failure = $this->taxonomy->failure($code);

    expect($failure)->toBeInstanceOf(ExecutionFailure::class);
})->with([
    ...array_filter(FailureCode::cases(), fn ($c) => str_starts_with($c->value, 'TOOL_')),
    FailureCode::RedisUnavailable,
    FailureCode::StorageUnavailable,
]);

it('types proxy-side faults as proxy failures', function (FailureCode $code): void {
    $failure = $this->taxonomy->failure($code);

    expect($failure)->toBeInstanceOf(ProxyFailure::class);
})->with([
    FailureCode::TcpTimeout,
    FailureCode::TcpRefused,
    FailureCode::AuthFailure,
    FailureCode::TlsFailure,
    FailureCode::MtprotoHandshakeFailed,
    FailureCode::Target4xx,
    FailureCode::Target5xx,
    FailureCode::JudgeUnavailable,
    FailureCode::JudgeInconsistent,
    FailureCode::SsrfBlocked,
    FailureCode::UnsupportedProtocol,
]);

it('wraps descriptor and context into produced failures', function (): void {
    $context = ['host' => '203.0.113.10', 'port' => 1080];

    $proxy = $this->taxonomy->failure(FailureCode::TcpTimeout, $context);
    $execution = $this->taxonomy->failure(FailureCode::ToolCrash);

    expect($proxy->descriptor()->code)->toBe(FailureCode::TcpTimeout)
        ->and($proxy->context())->toBe($context)
        ->and($execution->descriptor()->code)->toBe(FailureCode::ToolCrash)
        ->and($execution->context())->toBe([]);
});
