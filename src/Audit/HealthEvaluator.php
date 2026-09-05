<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Audit;

use BAGArt\ProxyOperations\Domain\Evidence\DimensionEvidence;
use BAGArt\ProxyOperations\Models\ProxyAccess;

/**
 * Per-dimension health evaluation and lifecycle transition on ProxyAccess
 * (plan §§11.6, 11.35 п.9). Contract introduced in T26; the production
 * implementation is T27's DimensionalHealthEvaluator. Must be callable
 * inside the ingestion transaction — no side effects after commit here.
 */
interface HealthEvaluator
{
    /**
     * Evaluate the evidence for one access; persists health and performs the
     * lifecycle transition.
     *
     * @param  list<DimensionEvidence>  $evidence
     */
    public function evaluate(ProxyAccess $access, array $evidence): HealthEvaluation;
}
