<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Domain\Evidence;

/**
 * Decides whether an access is eligible for the verified projection
 * (plan §11.35 items 9–11): capability-aware per protocol × available
 * evidence, never a single score threshold.
 *
 * Consumers: the verified_proxies projector on AuditCompleted and selection
 * pre-filtering (stage-05 pipeline).
 */
interface VerifiedEligibilityPolicy
{
    public function isEligible(VerifiedEligibilityCheck $check): bool;
}
