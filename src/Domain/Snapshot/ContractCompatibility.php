<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Domain\Snapshot;

/**
 * How a contract evolves (plan §11.12): additive changes keep wire
 * compatibility; semantic changes alter meaning under the same schema;
 * versioned contracts bump their major version.
 */
enum ContractCompatibility: string
{
    case Additive = 'additive';
    case Semantic = 'semantic';
    case Versioned = 'versioned';
}
