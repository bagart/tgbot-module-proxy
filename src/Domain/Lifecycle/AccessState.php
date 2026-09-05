<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Domain\Lifecycle;

/**
 * Canonical access lifecycle states (plan §11.6, R6.1).
 *
 * Lives on ProxyAccess (endpoint + credential), never on ProxyEndpoint.
 * Quarantine and testability are orthogonal statuses (see QuarantineStatus,
 * TestabilityStatus) — merging them into this enum would explode the state
 * space, which §11.6 explicitly forbids.
 */
enum AccessState: string
{
    case New = 'new';
    case Testing = 'testing';
    case Working = 'working';
    case Degraded = 'degraded';
    case Failing = 'failing';
    case Dead = 'dead';
    case Retired = 'retired';
}
