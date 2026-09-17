<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Domain\Lease;

/**
 * Contract for renewing proxy access leases during long-running probe
 * executions. Implemented by LeaseService in the Audit layer.
 */
interface LeaseRenewerContract
{
    /**
     * Renew a lease by its access ID. Returns true if renewal succeeded.
     */
    public function renewByAccessId(string $accessId): bool;
}
