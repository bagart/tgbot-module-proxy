<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Domain\Pool;

/**
 * Why a candidate was accepted or skipped (plan §11.25 — the "why skipped"
 * answer comes from the decision log, not from re-running the selector).
 * Additive only: new codes may appear, existing ones never change meaning.
 */
enum SelectionReasonCode: string
{
    case Accepted = 'accepted';
    case PredicateStateMismatch = 'predicate_state_mismatch';
    case PredicateProtocolMismatch = 'predicate_protocol_mismatch';
    case PredicateHealthBelowFloor = 'predicate_health_below_floor';
    case PredicateTelegramFilter = 'predicate_telegram_filter';
    case NotEligible = 'not_eligible';
    case AlreadyMember = 'already_member';
    case DuplicateCandidate = 'duplicate_candidate';
    case PredicateInvalid = 'predicate_invalid';

    // T35 (selection) extends the same catalog.
    case StaleHealth = 'stale_health';
    case LeaseUnavailable = 'lease_unavailable';
    case Excluded = 'excluded';
    case PoolEmpty = 'pool_empty';
}
