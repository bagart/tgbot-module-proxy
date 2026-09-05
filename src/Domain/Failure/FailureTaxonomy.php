<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Domain\Failure;

use RuntimeException;

/**
 * Central registry: complete code → descriptor mapping per plan §11.16.
 * New codes go through an ADR — attributes are mandatory.
 */
final class FailureTaxonomy
{
    /**
     * @var array<non-empty-string, array{0: bool, 1: bool, 2: bool, 3: bool, 4: bool}>
     */
    private const array ATTRIBUTES = [
        // retryable, countsAsFailure, affectsHealth, affectsCapability, quarantineAfterThreshold
        FailureCode::TcpTimeout->name => [true, true, true, false, true],
        FailureCode::TcpRefused->name => [true, true, true, false, true],
        FailureCode::AuthFailure->name => [false, true, true, true, true],
        // TLS is "partially" retryable in the plan; mapped to true = retry allowed with caution.
        FailureCode::TlsFailure->name => [true, true, true, true, false],
        FailureCode::MtprotoHandshakeFailed->name => [true, true, true, true, false],
        // TARGET_*: contextual columns resolved as soft health influence, never counted against the proxy.
        FailureCode::Target4xx->name => [false, false, true, false, false],
        FailureCode::Target5xx->name => [true, false, true, false, false],
        FailureCode::JudgeUnavailable->name => [true, false, false, false, false],
        FailureCode::JudgeInconsistent->name => [true, false, false, true, false],
        FailureCode::ToolTimeout->name => [true, false, false, false, false],
        FailureCode::ToolCrash->name => [true, false, false, false, false],
        FailureCode::ToolProtocolError->name => [true, false, false, false, false],
        FailureCode::ToolOom->name => [true, false, false, false, false],
        FailureCode::ToolExitFailure->name => [true, false, false, false, false],
        FailureCode::ToolOutputInvalid->name => [true, false, false, false, false],
        FailureCode::ToolUnavailable->name => [true, false, false, false, false],
        FailureCode::RedisUnavailable->name => [true, false, false, false, false],
        FailureCode::StorageUnavailable->name => [true, false, false, false, false],
        FailureCode::SsrfBlocked->name => [false, false, false, false, true],
        FailureCode::UnsupportedProtocol->name => [false, false, false, true, false],
    ];

    public function descriptor(FailureCode $code): FailureDescriptor
    {
        $attributes = self::ATTRIBUTES[$code->name]
            ?? throw new RuntimeException("Missing failure taxonomy entry for {$code->value}");

        return new FailureDescriptor(
            code: $code,
            class: $code->class(),
            retryable: $attributes[0],
            countsAsFailure: $attributes[1],
            affectsHealth: $attributes[2],
            affectsCapability: $attributes[3],
            quarantineAfterThreshold: $attributes[4],
        );
    }

    /**
     * Factory by responsibility axis: Checker/Platform faults produce
     * ExecutionFailure, everything else ProxyFailure.
     *
     * @param  array<string, mixed>  $context
     */
    public function failure(FailureCode $code, array $context = []): Failure
    {
        $descriptor = $this->descriptor($code);

        return $descriptor->class->isExecutionFailure()
            ? new ExecutionFailure($descriptor, $context)
            : new ProxyFailure($descriptor, $context);
    }
}
