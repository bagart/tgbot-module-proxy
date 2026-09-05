<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Domain\Failure;

/**
 * Common contract for probe failures. Callers handle both variants uniformly
 * through the descriptor; interpretation differs by FailureClass.
 */
interface Failure
{
    public function descriptor(): FailureDescriptor;

    /**
     * Free-form diagnostic context. MUST contain masked credentials only —
     * never raw secrets (they are forbidden in logs, exceptions and output).
     *
     * @return array<string, mixed>
     */
    public function context(): array;
}
