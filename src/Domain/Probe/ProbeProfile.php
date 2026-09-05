<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Domain\Probe;

/**
 * Named probe profile (plan §11.17). Policy/workspace selects a profile, not
 * scattered settings; the selected profile version feeds ProbeCacheKey.
 */
enum ProbeProfile: string
{
    case Light = 'light';
    case Standard = 'standard';
    case Deep = 'deep';
    case Telegram = 'telegram';
    case Bandwidth = 'bandwidth';
}
