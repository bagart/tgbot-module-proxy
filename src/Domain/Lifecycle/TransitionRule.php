<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Domain\Lifecycle;

use BAGArt\ProxyOperations\Domain\Failure\FailureCode;

/**
 * One legal edge of the access lifecycle graph (plan §11.6).
 *
 * Each rule pins the expected cause kind so a failure code can never drive a
 * recovery transition and vice versa.
 */
final readonly class TransitionRule
{
    public function __construct(
        public AccessState $from,
        public AccessState $to,
        public CauseKind $causeKind,
    ) {}

    /**
     * Whether the given cause satisfies this rule's polarity.
     */
    public function accepts(FailureCode|HealthSignal $cause): bool
    {
        return match (true) {
            $cause instanceof FailureCode => $this->causeKind === CauseKind::FailureCode,
            $cause instanceof HealthSignal => $this->causeKind === CauseKind::HealthSignal,
        };
    }
}
