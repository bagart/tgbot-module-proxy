<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Audit;

use BAGArt\ProxyOperations\Domain\Evidence\Applicability;
use BAGArt\ProxyOperations\Domain\Evidence\BandwidthEvidence;
use BAGArt\ProxyOperations\Domain\Evidence\DimensionEvidence;
use BAGArt\ProxyOperations\Domain\Evidence\DnsEvidence;
use BAGArt\ProxyOperations\Domain\Evidence\EvidenceApplicability;
use BAGArt\ProxyOperations\Domain\Evidence\EvidenceType;
use BAGArt\ProxyOperations\Domain\Evidence\HttpEvidence;
use BAGArt\ProxyOperations\Domain\Evidence\JudgeEvidence;
use BAGArt\ProxyOperations\Domain\Evidence\TcpEvidence;
use BAGArt\ProxyOperations\Domain\Evidence\TelegramEvidence;
use BAGArt\ProxyOperations\Domain\Evidence\TlsEvidence;
use BAGArt\ProxyOperations\Domain\Evidence\UdpEvidence;
use BAGArt\ProxyOperations\Domain\Failure\FailureCode;
use BAGArt\ProxyOperations\Domain\Identity\ProxyProtocol;
use BAGArt\ProxyOperations\Domain\Lifecycle\AccessState;
use BAGArt\ProxyOperations\Domain\Lifecycle\HealthSignal;
use BAGArt\ProxyOperations\Domain\Lifecycle\HysteresisPolicy;
use BAGArt\ProxyOperations\Domain\Lifecycle\LifecycleEvent;
use BAGArt\ProxyOperations\Models\ProxyAccess;
use BAGArt\ProxyOperations\Models\ProxyHealth;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Production HealthEvaluator (plan §§11.6, 11.35 пп.9–10, §11.29 IMPROVE#11,
 * R6.6): groups the batch evidence per dimension, classifies each applicable
 * dimension pass/fail, aggregates to a positive / negative / insufficient
 * decision, applies anti-flap hysteresis through the canonical
 * AccessStateMachine (one ladder step at a time, capability-aware — a DEAD
 * access with only a passing TCP check never jumps to WORKING), refreshes the
 * access-scoped ProxyHealth projection with its formula version, and derives
 * the Telegram freshness columns from TelegramEvidence (a stale flag is never
 * reported usable — the ProxyAccess::recordTransition() Working gate enforces
 * the same policy on every promotion).
 *
 * Must stay callable inside the ingestion transaction — all writes here are
 * plain Eloquent saves, no post-commit side effects.
 */
final class DimensionalHealthEvaluator implements HealthEvaluator
{
    public function __construct(
        private readonly HysteresisPolicy $hysteresis,
        private readonly EvidenceApplicability $applicability = new EvidenceApplicability,
        private readonly int $telegramFreshnessSeconds = 21600,
        private readonly string $healthFormulaVersion = 'dimensional-v1',
    ) {
        if ($this->telegramFreshnessSeconds < 1) {
            throw new InvalidArgumentException('Telegram freshness TTL must be >= 1 second');
        }
    }

    public function evaluate(ProxyAccess $access, array $evidence): HealthEvaluation
    {
        $protocol = $access->endpoint()->firstOrFail()->identity()->protocol;

        $perType = self::evidenceByType($evidence);
        $signals = self::dimensionSignals($perType, $protocol, $this->applicability);
        $decision = self::aggregate($signals, $protocol, $this->applicability);

        $stateBefore = $access->state;
        $telegramEvidence = $perType[EvidenceType::Telegram->value] ?? null;
        $failureCode = self::firstFailureCode($perType, $signals);

        if ($telegramEvidence instanceof TelegramEvidence) {
            self::applyTelegramFreshness($access, $telegramEvidence, $this->telegramFreshnessSeconds);
        }

        $access->forceFill([
            'last_checked_at' => now(),
            'consecutive_failures' => match ($decision) {
                self::NEGATIVE => $access->consecutive_failures + 1,
                self::POSITIVE => 0,
                default => $access->consecutive_failures,
            },
            'consecutive_successes' => match ($decision) {
                self::POSITIVE => $access->consecutive_successes + 1,
                self::NEGATIVE => 0,
                default => $access->consecutive_successes,
            },
        ])->save();

        $event = $this->transition($access, $decision, $failureCode);

        if ($event !== null && $decision === self::POSITIVE) {
            // A fired recovery transition consumes the success streak; the
            // failure ladder, in contrast, accumulates across escalations.
            $access->forceFill(['consecutive_successes' => 0])->save();
        }

        $this->persistHealth($access, $signals, $protocol);

        $access->refresh();

        return new HealthEvaluation(
            signal: $decision === self::POSITIVE ? HealthSignal::ProbeSucceeded : null,
            stateBefore: $stateBefore,
            stateAfter: $event?->to,
            healthFormulaVersion: $this->healthFormulaVersion,
            dimensionSignals: $signals,
            lifecycleEvent: $event,
            failureCode: $decision === self::NEGATIVE ? $failureCode : null,
        );
    }

    public const POSITIVE = 'positive';

    public const NEGATIVE = 'negative';

    public const INSUFFICIENT = 'insufficient';

    /**
     * First evidence of each type wins; the pipeline emits at most one
     * measurement per dimension per result batch.
     *
     * @param  list<DimensionEvidence>  $evidence
     * @return array<non-empty-string, DimensionEvidence>
     */
    private static function evidenceByType(array $evidence): array
    {
        $byType = [];

        foreach ($evidence as $item) {
            $byType[$item->type()->value] ??= $item;
        }

        return $byType;
    }

    /**
     * Per-dimension verdict for every dimension applicable to the protocol:
     * 'pass' | 'fail' | 'unknown' (no measurement for an applicable dimension)
     * | 'not_applicable' (dimension proven irrelevant for this protocol —
     * never blocks a classification, §11.35 п.9).
     *
     * @param  array<non-empty-string, DimensionEvidence>  $perType
     * @return array<non-empty-string, non-empty-string>
     */
    private static function dimensionSignals(
        array $perType,
        ProxyProtocol $protocol,
        EvidenceApplicability $applicability,
    ): array {
        $signals = [];

        foreach (EvidenceType::cases() as $type) {
            $applicabilityLevel = $applicability->for($protocol, $type);

            if ($applicabilityLevel === Applicability::NotApplicable) {
                $signals[$type->value] = 'not_applicable';

                continue;
            }

            $evidence = $perType[$type->value] ?? null;

            $signals[$type->value] = $evidence === null
                ? 'unknown'
                : (self::dimensionPassed($evidence) ? 'pass' : 'fail');
        }

        return $signals;
    }

    private static function dimensionPassed(DimensionEvidence $evidence): bool
    {
        return match (true) {
            $evidence instanceof TcpEvidence => $evidence->connectSucceeded && $evidence->failureCode === null,
            $evidence instanceof HttpEvidence => $evidence->succeeded && $evidence->failureCode === null,
            $evidence instanceof TlsEvidence => $evidence->handshakeSucceeded && $evidence->failureCode === null,
            $evidence instanceof DnsEvidence => $evidence->resolutionSucceeded && $evidence->failureCode === null,
            $evidence instanceof UdpEvidence => $evidence->associateSucceeded && $evidence->failureCode === null,
            $evidence instanceof JudgeEvidence => $evidence->reachable && $evidence->verdictConsistent,
            $evidence instanceof TelegramEvidence => $evidence->reachableDcIds !== [] && $evidence->failureCode === null,
            $evidence instanceof BandwidthEvidence => $evidence->transferCompleted,
            default => false,
        };
    }

    /**
     * Aggregate decision (§11.6 — evidence is not a linear ladder):
     * - positive: every REQUIRED dimension passed (optional dims may be
     *   unknown, none failing);
     * - negative: at least one applicable dimension explicitly failed;
     * - insufficient: no explicit failure but the required set is incomplete —
     *   never classifies (this is why DEAD + a lone passing TCP check does
     *   not resurrect), and never escalates either.
     *
     * @param  array<non-empty-string, non-empty-string>  $signals
     */
    private static function aggregate(
        array $signals,
        ProxyProtocol $protocol,
        EvidenceApplicability $applicability,
    ): string {
        $requiredPass = true;
        $anyApplicableFail = false;

        foreach ($applicability->applicableFor($protocol) as $type) {
            $signal = $signals[$type->value];

            if ($signal === 'fail') {
                $anyApplicableFail = true;
            }

            if ($applicability->for($protocol, $type) === Applicability::Required && $signal !== 'pass') {
                $requiredPass = false;
            }
        }

        return match (true) {
            $anyApplicableFail => self::NEGATIVE,
            $requiredPass => self::POSITIVE,
            default => self::INSUFFICIENT,
        };
    }

    /**
     * One legal ladder step (if any) through ProxyAccess::recordTransition(),
     * guarded by hysteresis counters and the dwell flap guard.
     */
    private function transition(ProxyAccess $access, string $decision, ?FailureCode $failureCode): ?LifecycleEvent
    {
        if ($decision === self::INSUFFICIENT) {
            return null; // no classification material: hold the current state
        }

        $state = $access->state;

        if (! $this->dwellAllows($access)) {
            return null;
        }

        if ($decision === self::NEGATIVE) {
            $target = $this->hysteresis->escalationTarget($state, $access->consecutive_failures);

            return $target === null ? null : $access->recordTransition($target, $failureCode ?? FailureCode::ToolExitFailure);
        }

        return $this->recoveryTransition($access, $state);
    }

    /**
     * Positive direction. New/Testing classifications are immediate (first
     * full classification, §11.6); Degraded/Failing recover one step only
     * after the configured consecutive successes; WORKING additionally
     * requires a fresh Telegram check (§11.35 п.10 gate inside
     * recordTransition — without fresh Telegram evidence the promotion is
     * held). DEAD/RETIRED never move on probe evidence: resurrection is a
     * full re-test (RecheckTriggered), not a partial pass.
     */
    private function recoveryTransition(ProxyAccess $access, AccessState $state): ?LifecycleEvent
    {
        if ($state === AccessState::New) {
            $event = $access->recordTransition(AccessState::Testing, HealthSignal::ProbeSucceeded);

            // First full classification may chain into the ladder: Testing is
            // an intermediate step, not a target state to rest on.
            return $this->recoveryTransition($access, AccessState::Testing) ?? $event;
        }

        if ($state === AccessState::Testing) {
            return $this->canPromoteToWorking($access)
                ? $access->recordTransition(AccessState::Working, HealthSignal::ProbeSucceeded)
                : null;
        }

        $target = $this->hysteresis->recoveryTarget($state, $access->consecutive_successes);

        if ($target === null) {
            return null;
        }

        if ($target === AccessState::Working && ! $this->canPromoteToWorking($access)) {
            return null;
        }

        return $access->recordTransition($target, HealthSignal::HealthRecovered);
    }

    /**
     * §11.35 п.10: promotion to WORKING needs a fresh Telegram check —
     * derived from the persisted columns exactly like consumers see them.
     */
    private function canPromoteToWorking(ProxyAccess $access): bool
    {
        return $access->telegramUsableNow() === true;
    }

    private function dwellAllows(ProxyAccess $access): bool
    {
        if ($this->hysteresis->minSecondsBetweenTransitions === 0 || $access->state_changed_at === null) {
            return true;
        }

        return $this->hysteresis->allowsFlip($access->state_changed_at, new DateTimeImmutable);
    }

    /**
     * Persists the Telegram freshness block from TelegramEvidence (§11.35
     * п.10): `telegram_usable` is a raw last-check fact; consumers derive
     * usability through telegramUsableNow(), which never reports a stale
     * flag as true.
     */
    private static function applyTelegramFreshness(ProxyAccess $access, TelegramEvidence $evidence, int $ttlSeconds): void
    {
        $measuredAt = $evidence->measuredAt;

        $access->forceFill([
            'telegram_connectivity' => $evidence->reachableDcIds !== [],
            'telegram_usable' => $evidence->reachableDcIds !== [] && $evidence->failureCode === null,
            'telegram_checked_at' => $measuredAt,
            'telegram_fresh_until' => $measuredAt->modify("+{$ttlSeconds} seconds"),
            'telegram_evidence_version' => 'tg-dc:'.$evidence->dcSetVersion,
        ]);
    }

    /**
     * Refreshes the access-scoped health projection (R6.6: formula version
     * stored next to every derived value). Formula v1: health_score is the
     * share of applicable dimensions passed; capability_score is the share of
     * applicable OPTIONAL dimensions passed (IMPROVE#1 keeps the two apart).
     */
    private function persistHealth(ProxyAccess $access, array $signals, ProxyProtocol $protocol): void
    {
        $applicable = $this->applicability->applicableFor($protocol);
        $passed = 0;
        $optionalTotal = 0;
        $optionalPassed = 0;

        foreach ($applicable as $type) {
            $signal = $signals[$type->value] ?? 'unknown';

            if ($signal === 'pass') {
                $passed++;
            }

            if ($this->applicability->for($protocol, $type) === Applicability::Optional) {
                $optionalTotal++;

                if ($signal === 'pass') {
                    $optionalPassed++;
                }
            }
        }

        ProxyHealth::query()->updateOrCreate(
            [
                'tenant_id' => $access->tenant_id,
                'access_id' => $access->id,
            ],
            [
                'health_score' => $applicable === [] ? null : (int) round(100 * $passed / count($applicable)),
                'capability_score' => $optionalTotal === 0 ? null : (int) round(100 * $optionalPassed / $optionalTotal),
                'dimension_signals' => $signals,
                'health_formula_version' => $this->healthFormulaVersion,
                'fresh_until' => now()->addSeconds($this->telegramFreshnessSeconds),
                'computed_at' => now(),
            ],
        );
    }

    /**
     * Failure code driving negative transitions: the first reported code of
     * a failing dimension; ToolExitFailure when evidence failed without a code.
     *
     * @param  array<non-empty-string, DimensionEvidence>  $perType
     * @param  array<non-empty-string, non-empty-string>  $signals
     */
    private static function firstFailureCode(array $perType, array $signals): ?FailureCode
    {
        foreach ($perType as $typeName => $evidence) {
            if (($signals[$typeName] ?? 'unknown') !== 'fail') {
                continue;
            }

            $code = match (true) {
                $evidence instanceof TcpEvidence => $evidence->failureCode,
                $evidence instanceof HttpEvidence => $evidence->failureCode,
                $evidence instanceof TlsEvidence => $evidence->failureCode,
                $evidence instanceof DnsEvidence => $evidence->failureCode,
                $evidence instanceof UdpEvidence => $evidence->failureCode,
                $evidence instanceof JudgeEvidence => $evidence->failureCode,
                $evidence instanceof TelegramEvidence => $evidence->failureCode,
                $evidence instanceof BandwidthEvidence => $evidence->failureCode,
                default => null,
            };

            if ($code !== null) {
                return $code;
            }
        }

        return FailureCode::ToolExitFailure;
    }
}
