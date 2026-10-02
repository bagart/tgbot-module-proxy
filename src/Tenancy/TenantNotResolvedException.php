<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Tenancy;

use RuntimeException;

/**
 * Thrown when tenancy-sensitive code runs without an authenticated workspace
 * resolved in the current scope (INV-006 — fail closed, never fall back
 * to unscoped access).
 */
final class TenantNotResolvedException extends RuntimeException
{
}
