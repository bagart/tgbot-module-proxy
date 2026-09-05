<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Domain\Lifecycle;

/**
 * Kind of cause a transition rule expects: a concrete FailureCode (regression)
 * or a HealthSignal (promotion/recovery/recheck).
 */
enum CauseKind: string
{
    case FailureCode = 'failure_code';
    case HealthSignal = 'health_signal';
}
