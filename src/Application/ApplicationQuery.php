<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Application;

/**
 * Base contract for all application queries (plan §11.10, §11.29).
 */
interface ApplicationQuery
{
    public string $tenantId { get; }
}
