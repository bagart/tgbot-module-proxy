<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Domain\Pool;

/**
 * Pool kinds (plan §§11.21, 11.25): the kind decides who owns the member
 * projection — static members are hand-picked, dynamic members are
 * materialized from the predicate, hybrid keeps both.
 */
enum PoolKind: string
{
    case Static = 'static';
    case Dynamic = 'dynamic';
    case Hybrid = 'hybrid';
}
