<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Audit;

use BAGArt\ProxyOperations\Models\AuditTrigger;

/**
 * Scheduler → JobStarter placement request (plan §11.27): the Scheduler owns
 * WHEN, the JobStarter captures WHAT — so the request carries only the
 * placement facts: what triggered the run, which probe profile to audit with
 * (judge-outage downgrades are decided scheduler-side before this point) and
 * the target access ids.
 */
final readonly class AuditRequest
{
    /**
     * @param  list<non-empty-string>  $accessIds  ProxyAccess UUIDs to audit.
     * @param  non-empty-string  $probeProfile  ProbeProfile value for this run.
     */
    public function __construct(
        public readonly AuditTrigger $trigger,
        public readonly string $probeProfile,
        public readonly array $accessIds,
        public readonly ?int $requestedBy,
    ) {}
}
