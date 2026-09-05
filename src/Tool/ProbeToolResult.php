<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Tool;

use BAGArt\ProxyOperations\Domain\Failure\ExecutionFailure;

/**
 * Outcome of a single probe execution (plan §11.39 пп.5,14): either raw
 * observations with timings that the evidence pipeline interprets, or an
 * ExecutionFailure — TOOL_* faults never become proxy observations
 * (INV-014/015).
 */
final readonly class ProbeToolResult
{
    /**
     * @param  array<string, mixed>  $observations  Raw observations; interpreted by PHP, not by the tool.
     * @param  array<non-empty-string, float>  $timingsMs  Named wall-clock timings in milliseconds.
     */
    private function __construct(
        public readonly bool $ok,
        public readonly array $observations,
        public readonly array $timingsMs,
        public readonly ?ExecutionFailure $failure,
    ) {}

    /**
     * @param  array<string, mixed>  $observations
     * @param  array<non-empty-string, float>  $timingsMs
     */
    public static function ok(array $observations, array $timingsMs): self
    {
        return new self(ok: true, observations: $observations, timingsMs: $timingsMs, failure: null);
    }

    /**
     * @param  array<non-empty-string, float>  $timingsMs
     */
    public static function failed(ExecutionFailure $failure, array $timingsMs): self
    {
        return new self(ok: false, observations: [], timingsMs: $timingsMs, failure: $failure);
    }
}
