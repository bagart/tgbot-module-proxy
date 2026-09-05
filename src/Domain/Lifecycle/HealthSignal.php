<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Domain\Lifecycle;

/**
 * Positive health signals feeding the state machine (plan §11.6 pipeline:
 * ProbeOutcome → HealthEvaluation → StateTransition).
 *
 * The negative branch of causes is FailureCode (Domain\Failure); every other
 * legal transition cause is one of these signals.
 */
enum HealthSignal: string
{
    /** A probe outcome satisfied the evaluated dimensions (first classification or promotion). */
    case ProbeSucceeded = 'probe_succeeded';

    /** Health evaluation confirmed sustained recovery after degradation/failure. */
    case HealthRecovered = 'health_recovered';

    /** Scheduler/lazy-selection triggered a re-audit; the access re-enters Testing. */
    case RecheckTriggered = 'recheck_triggered';

    /** Workspace owner requested a manual re-audit. */
    case ManualRecheck = 'manual_recheck';

    /** Owner/admin decision to permanently retire the access. */
    case ManualRetirement = 'manual_retirement';
}
