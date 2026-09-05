<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Domain\Policy;

use RuntimeException;

/**
 * SSRF policy for proxy → target content/liveness fetches (plan §11.35 п.14):
 * denylist of private/metadata destinations plus marker protection — every
 * probe request carries a per-job marker that the fetched body must echo back,
 * proving the response came through the audited path and not from a local
 * interceptor or the judge itself. Redirect following is off by default so a
 * target cannot bounce the probe into a private destination. Pure policy data.
 */
final readonly class TargetFetchPolicy
{
    /**
     * @param  string  $markerHeaderName  Header carrying the per-job marker.
     * @param  string  $markerSecret  Per-job marker value the fetched body must echo back.
     */
    public function __construct(
        public readonly IpDenylist $denylist,
        public readonly string $markerHeaderName = 'x-po-marker',
        public readonly string $markerSecret = '',
        public readonly bool $followRedirects = false,
    ) {}

    /**
     * Default hardened configuration: standard SSRF denylist, fresh random
     * marker and no redirect following.
     */
    public static function hardened(): self
    {
        return new self(
            denylist: IpDenylist::ssrfDefault(),
            markerSecret: bin2hex(random_bytes(16)),
            followRedirects: false,
        );
    }

    /**
     * Fail-closed verdict for a resolved target address.
     */
    public function allowsTargetIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        try {
            return ! $this->denylist->contains($ip);
        } catch (RuntimeException) {
            return false;
        }
    }
}
