<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Domain\Lifecycle;

/**
 * Orthogonal testability status of a ProxyAccess (plan §11.6).
 *
 * Kept out of AccessState on purpose: NOT_TESTABLE is a property of the
 * checker/tooling side, not a lifecycle phase.
 */
enum TestabilityStatus: string
{
    case Testable = 'testable';
    case NotTestable = 'not_testable';
}
