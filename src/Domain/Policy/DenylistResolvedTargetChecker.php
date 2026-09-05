<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Domain\Policy;

use RuntimeException;

/**
 * Denylist-backed ResolvedTargetChecker: every resolved address must be valid
 * and outside the denylist. Fails closed — malformed or empty resolution
 * results are rejected.
 */
final readonly class DenylistResolvedTargetChecker implements ResolvedTargetChecker
{
    public function __construct(
        public readonly IpDenylist $denylist,
    ) {}

    public function isConnectionAllowed(string $host, array $resolvedIps): bool
    {
        if ($resolvedIps === []) {
            return false;
        }

        try {
            foreach ($resolvedIps as $ip) {
                if ($this->denylist->contains((string) $ip)) {
                    return false;
                }
            }
        } catch (RuntimeException) {
            return false;
        }

        return true;
    }
}
