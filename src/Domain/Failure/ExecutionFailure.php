<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Domain\Failure;

/**
 * Checker-infrastructure failure (classes Checker, Platform): a TOOL_*,
 * REDIS_* or STORAGE_* fault. Never becomes a proxy observation and never
 * changes proxy health — a checker timeout is not a proxy timeout (INV-014/015).
 * Counted in checker-node metrics and delivery retry/DLQ logic instead.
 */
final readonly class ExecutionFailure implements Failure
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public FailureDescriptor $descriptor,
        public array $context = [],
    ) {
    }

    public function descriptor(): FailureDescriptor
    {
        return $this->descriptor;
    }

    public function context(): array
    {
        return $this->context;
    }
}
