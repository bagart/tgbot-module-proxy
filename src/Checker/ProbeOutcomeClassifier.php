<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Checker;

use BAGArt\ProxyOperations\Domain\Failure\FailureCode;
use BAGArt\ProxyOperations\Domain\Failure\FailureTaxonomy;
use BAGArt\ProxyOperations\Domain\Failure\ProxyFailure;
use BAGArt\ProxyOperations\Tool\ProbeToolResult;
use InvalidArgumentException;

/**
 * Pure classifier for raw probe outcomes (plan §§11.16, 11.39 пп.13–14).
 *
 * Tool contract (T91/T20) in this codebase:
 * - `ProbeToolResult::ok === true` with no failure marker → Success. Raw
 *   observations are evidence payload for the downstream pipeline.
 * - `ProbeToolResult::ok === true` with observations carrying a
 *   self-describing `failureCode` key (a FailureCode value) → the tool ran
 *   fine but the probe recorded a proxy-side failure (TCP_REFUSED,
 *   AUTH_FAILURE, ...): classified as ProxyFailure.
 * - `ProbeToolResult::ok === false` → classified by the failure descriptor's
 *   FailureClass: Checker/Platform → ExecutionFailure (tool crash, tool
 *   timeout, tool unavailable, REDIS_ or STORAGE_ codes); Proxy/Target/Judge/Policy
 *   → ProxyFailure.
 *
 * INV-014/015: a TOOL_ / REDIS_ / STORAGE_ code can never surface as a
 * ProxyFailure from this class.
 */
final readonly class ProbeOutcomeClassifier
{
    /** Observation key carrying a self-describing FailureCode value. */
    public const string FAILURE_CODE_KEY = 'failureCode';

    public function __construct(
        private readonly FailureTaxonomy $taxonomy,
    ) {
    }

    /**
     * @return array{0: ProbeOutcomeClassification, 1: ProxyFailure|null}
     */
    public function classify(ProbeToolResult $result): array
    {
        if ($result->failure !== null) {
            $failure = $result->failure;

            if ($failure->descriptor->class->isExecutionFailure()) {
                return [ProbeOutcomeClassification::ExecutionFailure, null];
            }

            // A tool-reported failure whose class is proxy-side (defensive:
            // ProbeToolResult::failed types ExecutionFailure, but the
            // descriptor decides, not the wrapper type).
            return [ProbeOutcomeClassification::ProxyFailure, new ProxyFailure($failure->descriptor, $failure->context)];
        }

        $codeValue = $result->observations[self::FAILURE_CODE_KEY] ?? null;

        if ($codeValue === null) {
            return [ProbeOutcomeClassification::Success, null];
        }

        $code = $codeValue instanceof FailureCode
            ? $codeValue
            : FailureCode::from((string) $codeValue);

        $descriptor = $this->taxonomy->descriptor($code);

        if ($descriptor->class->isExecutionFailure()) {
            // Tool contract violation: execution-class codes travel only via
            // ProbeToolResult::failed, never inside observations (INV-014/015).
            throw new InvalidArgumentException(
                "Observations carry an execution-class failure code {$code->value}; it must never become a proxy observation (INV-014/015).",
            );
        }

        // Strip the marker key: the ProxyFailure context carries the raw
        // observation payload (headers, exit IP, ...) for the evidence
        // pipeline without duplicating the descriptor code.
        $context = $result->observations;
        unset($context[self::FAILURE_CODE_KEY]);

        return [ProbeOutcomeClassification::ProxyFailure, new ProxyFailure($descriptor, $context)];
    }
}
