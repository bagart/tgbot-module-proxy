<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Domain\Lease;

/**
 * Lease lifecycle states (plan §11.24): active → released (holder),
 * expired (reaper), stolen (forced takeover — reserved). Postgres is the
 * truth; the Redis lock never decides the state.
 */
enum LeaseState: string
{
    case Active = 'active';
    case Released = 'released';
    case Expired = 'expired';
    case Stolen = 'stolen';
}
