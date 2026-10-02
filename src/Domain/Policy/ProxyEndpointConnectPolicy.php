<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Domain\Policy;

use RuntimeException;

/**
 * SSRF policy for checker → proxy endpoint connections (plan §11.35 п.14).
 *
 * Unlike judge and target traffic, a proxy host MAY legitimately be a private
 * IP (inventory of internal/corporate proxies), so this policy can opt out of
 * the private-range denylist via `allowPrivateEndpoints`. `resolveThenConnect`
 * is mandatory in safe configurations: connect only to pre-resolved addresses,
 * never let the transport re-resolve (anti-DNS-rebinding). Pure policy data.
 */
final readonly class ProxyEndpointConnectPolicy
{
    public function __construct(
        public readonly IpDenylist $denylist,
        public readonly bool $allowPrivateEndpoints,
        public readonly bool $resolveThenConnect,
    ) {
    }

    /**
     * Default inventory-audit configuration: private proxy hosts are allowed,
     * connections go only to pre-resolved addresses.
     */
    public static function forInventoryAudit(): self
    {
        return new self(
            denylist: IpDenylist::ssrfDefault(),
            allowPrivateEndpoints: true,
            resolveThenConnect: true,
        );
    }

    /**
     * Fail-closed verdict for a resolved endpoint address: syntactically valid
     * and either explicitly allowed as private or outside the denylist.
     */
    public function allowsEndpointIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        if ($this->allowPrivateEndpoints) {
            return true;
        }

        try {
            return ! $this->denylist->contains($ip);
        } catch (RuntimeException) {
            return false;
        }
    }
}
