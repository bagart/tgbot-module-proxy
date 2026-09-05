<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Domain\Lifecycle;

use BAGArt\ProxyOperations\Domain\Failure\FailureCode;
use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;

/**
 * The single canonical access lifecycle state machine (plan §11.6, R6.1).
 *
 * Transition graph (one step at a time on the failure/recovery ladder; the
 * DEAD → light-TCP-OK → WORKING shortcut from plan §11.35 item 9 is illegal —
 * resurrection goes through a full re-test):
 *
 *   New      → Testing
 *   Testing  → Working | Degraded | Failing | Dead   (first full classification)
 *   Working  → Testing | Degraded
 *   Degraded → Testing | Working | Failing
 *   Failing  → Testing | Degraded | Dead
 *   Dead     → Testing | Retired
 *   Retired  → (terminal)
 */
final class AccessStateMachine
{
    /**
     * Map of state name → target state name → rule.
     *
     * @var array<non-empty-string, array<non-empty-string, TransitionRule>>
     */
    private const array RULES = [
        AccessState::New->name => [
            AccessState::Testing->name => CauseKind::HealthSignal,
        ],
        AccessState::Testing->name => [
            AccessState::Working->name => CauseKind::HealthSignal,
            AccessState::Degraded->name => CauseKind::FailureCode,
            AccessState::Failing->name => CauseKind::FailureCode,
            AccessState::Dead->name => CauseKind::FailureCode,
        ],
        AccessState::Working->name => [
            AccessState::Testing->name => CauseKind::HealthSignal,
            AccessState::Degraded->name => CauseKind::FailureCode,
        ],
        AccessState::Degraded->name => [
            AccessState::Testing->name => CauseKind::HealthSignal,
            AccessState::Working->name => CauseKind::HealthSignal,
            AccessState::Failing->name => CauseKind::FailureCode,
        ],
        AccessState::Failing->name => [
            AccessState::Testing->name => CauseKind::HealthSignal,
            AccessState::Degraded->name => CauseKind::HealthSignal,
            AccessState::Dead->name => CauseKind::FailureCode,
        ],
        AccessState::Dead->name => [
            AccessState::Testing->name => CauseKind::HealthSignal,
            AccessState::Retired->name => CauseKind::HealthSignal,
        ],
        AccessState::Retired->name => [],
    ];

    /**
     * @return list<TransitionRule>
     */
    public function rules(): array
    {
        $rules = [];

        foreach (AccessState::cases() as $from) {
            foreach (self::RULES[$from->name] as $targetName => $causeKind) {
                $rules[] = new TransitionRule($from, self::stateByName($targetName), $causeKind);
            }
        }

        return $rules;
    }

    /**
     * RULES is keyed by case names (stable against value renames).
     */
    private static function stateByName(string $name): AccessState
    {
        foreach (AccessState::cases() as $case) {
            if ($case->name === $name) {
                return $case;
            }
        }

        throw new LogicException("Unknown AccessState name: {$name}");
    }

    /**
     * @return list<AccessState>
     */
    public function allowedTargets(AccessState $from): array
    {
        return array_map(
            static fn (string $targetName): AccessState => AccessState::from($targetName),
            array_keys(self::RULES[$from->name]),
        );
    }

    public function isAllowed(AccessState $from, AccessState $to): bool
    {
        return isset(self::RULES[$from->name][$to->name]);
    }

    /**
     * Applies one transition and returns its event.
     *
     * @param  FailureCode|HealthSignal  $cause  What drove the transition; must
     *                                           match the rule's expected polarity.
     *
     * @throws InvalidArgumentException On an illegal transition or a cause that
     *                                  does not match the rule's polarity.
     */
    public function transition(AccessState $from, AccessState $to, FailureCode|HealthSignal $cause): LifecycleEvent
    {
        $expectedCauseKind = self::RULES[$from->name][$to->name]
            ?? throw new InvalidArgumentException("Illegal lifecycle transition {$from->value} → {$to->value} (plan §11.6)");

        $causeMatches = match (true) {
            $cause instanceof FailureCode => $expectedCauseKind === CauseKind::FailureCode,
            $cause instanceof HealthSignal => $expectedCauseKind === CauseKind::HealthSignal,
        };

        if (! $causeMatches) {
            $expected = $expectedCauseKind === CauseKind::FailureCode ? 'a failure code' : 'a health signal';
            throw new InvalidArgumentException("Transition {$from->value} → {$to->value} requires {$expected}, got unexpected cause kind");
        }

        return new LifecycleEvent(
            from: $from,
            to: $to,
            reason: $cause instanceof FailureCode ? $cause : null,
            occurredAt: new DateTimeImmutable,
        );
    }
}
