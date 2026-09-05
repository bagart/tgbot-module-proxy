<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Domain\Evidence;

use DateTimeImmutable;

/**
 * Contract for one dimension-specific raw measurement (plan §11.35 item 9,
 * R6.3, R6.4).
 *
 * Implementations carry tenant-neutral raw observations only: no health score,
 * no lifecycle state, no tenant_id — shared cache values may hold them as-is
 * while interpretation stays per-workspace (plan §11.35 item 2).
 *
 * Consumers: HealthEvaluation per dimension → StateTransitionDecision pipeline
 * (plan §11.35 item 9), VerifiedEligibilityPolicy, ProbeCache value allowlist.
 */
interface DimensionEvidence
{
    public function type(): EvidenceType;

    public function measuredAt(): DateTimeImmutable;
}
