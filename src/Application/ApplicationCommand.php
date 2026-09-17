<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Application;

/**
 * Base contract for all application commands (plan §11.10, §11.29).
 * TenantId comes from the authenticated context, NEVER from client input.
 */
interface ApplicationCommand
{
    public string $tenantId { get; }
}
