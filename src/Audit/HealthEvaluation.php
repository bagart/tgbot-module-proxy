<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Audit;

use BAGArt\ProxyOperations\Domain\Evidence\EvidenceType;
use BAGArt\ProxyOperations\Domain\Failure\FailureCode;
use BAGArt\ProxyOperations\Domain\Lifecycle\AccessState;
use BAGArt\ProxyOperations\Domain\Lifecycle\HealthSignal;
use BAGArt\ProxyOperations\Domain\Lifecycle\LifecycleEvent;

/**
 * Outcome of one health evaluation (plan §§11.6, 11.35 п.9): the aggregated
 * health signal plus the lifecycle transition it produced, if any. The
 * `stateAfter` of a no-transition evaluation is null; `stateBefore` is null
 * when no prior state was recorded. Implementations persist health and apply
 * the transition themselves, inside the ingestion transaction.
 *
 * T27 additions (additive; constructor stays positional-compatible):
 * `signal` is null when the evidence aggregate produced no positive health
 * signal (negative or insufficient evidence — the negative branch of causes
 * is FailureCode, not a HealthSignal); `dimensionSignals` carries the
 * per-dimension verdicts; `lifecycleEvent` is the final transition applied.
 */
final readonly class HealthEvaluation
{
    public function __construct(
        public readonly ?HealthSignal $signal,
        public readonly ?AccessState $stateBefore,
        public readonly ?AccessState $stateAfter,
        public readonly string $healthFormulaVersion,
        /**
         * EvidenceType value → verdict ('pass' | 'fail' | 'unknown' | 'not_applicable').
         *
         * @var array<non-empty-string, non-empty-string>
         */
        public readonly array $dimensionSignals = [],
        public readonly ?LifecycleEvent $lifecycleEvent = null,
        public readonly ?FailureCode $failureCode = null,
    ) {}

    /**
     * Verdict for one dimension under this evaluation.
     */
    public function dimensionSignal(EvidenceType $type): ?string
    {
        return $this->dimensionSignals[$type->value] ?? null;
    }
}
