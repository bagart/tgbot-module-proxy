<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Tests\Fixtures;

use BAGArt\ProxyOperations\Audit\HealthEvaluation;
use BAGArt\ProxyOperations\Audit\HealthEvaluator;
use BAGArt\ProxyOperations\Domain\Evidence\DimensionEvidence;
use BAGArt\ProxyOperations\Domain\Lifecycle\AccessState;
use BAGArt\ProxyOperations\Domain\Lifecycle\HealthSignal;
use BAGArt\ProxyOperations\Models\ProxyAccess;
use Illuminate\Support\Facades\DB;

/**
 * Capturing HealthEvaluator fake for ingestion tests: records every evaluate()
 * call (access id, evidence, DB transaction depth at call time) and replays a
 * canned evaluation.
 */
final class CapturingHealthEvaluator implements HealthEvaluator
{
    /** @var list<array{accessId: string, evidence: list<DimensionEvidence>, transactionLevel: int}> */
    public array $calls = [];

    public ?AccessState $stateAfter = null;

    public function evaluate(ProxyAccess $access, array $evidence): HealthEvaluation
    {
        $this->calls[] = [
            'accessId' => $access->id,
            'evidence' => $evidence,
            'transactionLevel' => DB::transactionLevel(),
        ];

        return new HealthEvaluation(
            signal: HealthSignal::ProbeSucceeded,
            stateBefore: $access->state,
            stateAfter: $this->stateAfter,
            healthFormulaVersion: 'test-formula-v1',
        );
    }
}
