<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Domain\Lifecycle;

/**
 * Orthogonal quarantine status of a ProxyAccess (plan §11.6).
 *
 * Quarantine is applied by failure taxonomy rules (quarantineAfterThreshold,
 * plan §11.16) independently of the AccessState progression.
 */
enum QuarantineStatus: string
{
    case None = 'none';
    case Quarantined = 'quarantined';
}
