<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Domain\Probe;

/**
 * Timeout budget tier of a profile (plan §11.17).
 */
enum TimeoutTier: string
{
    case Aggressive = 'aggressive';
    case Standard = 'standard';
    case Generous = 'generous';
}
