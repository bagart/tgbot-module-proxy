<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Domain\Lifecycle;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Hysteresis thresholds guarding the lifecycle ladder against flapping
 * (plan §11.6 "consecutive-failures — вход hysteresis", §11.13 task change #29).
 *
 * Consumers: the health-evaluation pipeline (ProbeOutcome → HealthEvaluation →
 * StateTransitionDecision, plan §11.35 item 9) and the incident anti-flap policy.
 *
 * Escalation and recovery move one ladder step at a time; the returned target
 * is always an edge of AccessStateMachine, so both policies stay coherent.
 */
final readonly class HysteresisPolicy
{
    /**
     * @param  int  $consecutiveFailuresToDegrade  Failures needed to step Working → Degraded.
     * @param  int  $consecutiveFailuresToFailing  Failures needed to step Degraded → Failing.
     * @param  int  $consecutiveFailuresToDeclareDead  Failures needed to step Failing → Dead.
     * @param  int  $consecutiveSuccessesToLeaveFailing  Successes needed to step Failing → Degraded.
     * @param  int  $consecutiveSuccessesToLeaveDegraded  Successes needed to step Degraded → Working.
     * @param  int  $minSecondsBetweenTransitions  Dwell time in the current state before any flip is allowed.
     */
    public function __construct(
        public int $consecutiveFailuresToDegrade = 2,
        public int $consecutiveFailuresToFailing = 5,
        public int $consecutiveFailuresToDeclareDead = 10,
        public int $consecutiveSuccessesToLeaveFailing = 2,
        public int $consecutiveSuccessesToLeaveDegraded = 3,
        public int $minSecondsBetweenTransitions = 60,
    ) {
        if (min(
            $this->consecutiveFailuresToDegrade,
            $this->consecutiveFailuresToFailing,
            $this->consecutiveFailuresToDeclareDead,
            $this->consecutiveSuccessesToLeaveFailing,
            $this->consecutiveSuccessesToLeaveDegraded,
        ) < 1) {
            throw new InvalidArgumentException('Hysteresis counters must be >= 1');
        }

        if ($this->consecutiveFailuresToDegrade > $this->consecutiveFailuresToFailing
            || $this->consecutiveFailuresToFailing > $this->consecutiveFailuresToDeclareDead) {
            throw new InvalidArgumentException('Escalation thresholds must be strictly ascending');
        }

        if ($this->minSecondsBetweenTransitions < 0) {
            throw new InvalidArgumentException('Dwell guard must be >= 0 seconds');
        }
    }

    /**
     * Next escalation step for the current state once consecutive failures
     * reach a threshold; null while hysteresis holds the current state.
     */
    public function escalationTarget(AccessState $current, int $consecutiveFailures): ?AccessState
    {
        return match ($current) {
            AccessState::Working => $consecutiveFailures >= $this->consecutiveFailuresToDegrade ? AccessState::Degraded : null,
            AccessState::Degraded => $consecutiveFailures >= $this->consecutiveFailuresToFailing ? AccessState::Failing : null,
            AccessState::Failing => $consecutiveFailures >= $this->consecutiveFailuresToDeclareDead ? AccessState::Dead : null,
            default => null,
        };
    }

    /**
     * Next recovery step for the current state once consecutive successes
     * reach a threshold; null while hysteresis holds the current state.
     */
    public function recoveryTarget(AccessState $current, int $consecutiveSuccesses): ?AccessState
    {
        return match ($current) {
            AccessState::Failing => $consecutiveSuccesses >= $this->consecutiveSuccessesToLeaveFailing ? AccessState::Degraded : null,
            AccessState::Degraded => $consecutiveSuccesses >= $this->consecutiveSuccessesToLeaveDegraded ? AccessState::Working : null,
            default => null,
        };
    }

    /**
     * Flap guard: a flip is allowed only after the access dwelled in its
     * current state for the configured minimum. Timestamps are explicit
     * parameters so evaluation is testable without clock mocks.
     */
    public function allowsFlip(DateTimeImmutable $enteredCurrentStateAt, DateTimeImmutable $candidateAt): bool
    {
        return $candidateAt->getTimestamp() - $enteredCurrentStateAt->getTimestamp() >= $this->minSecondsBetweenTransitions;
    }
}
