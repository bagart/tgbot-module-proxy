<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Domain\Lease;

use BAGArt\ProxyOperations\Domain\Pool\SelectionReasonCode;

/**
 * One explainable selection decision (plan §11.25): why this candidate was
 * taken or skipped. Persisted in the shared decision-log table
 * (proxy_pool_decisions) with materialization_id = selection run id.
 */
final readonly class SelectionDecision
{
    public function __construct(
        public readonly ?string $accessId,
        public readonly string $decision, // selected | skipped
        public readonly SelectionReasonCode $reasonCode,
        public readonly ?float $score,
        public readonly int $policyVersion,
    ) {}
}
