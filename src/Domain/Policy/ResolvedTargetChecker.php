<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Domain\Policy;

/**
 * Anti-DNS-rebinding hook (plan §11.35 п.14 "resolve-then-connect"): the
 * checker MUST call this after DNS resolution and before opening any
 * connection; a negative verdict aborts the outbound attempt with SSRF_BLOCKED.
 * Implementations decide per policy which resolved addresses are acceptable.
 */
interface ResolvedTargetChecker
{
    /**
     * @param  string  $host  Hostname as requested (IP literals pass through as-is).
     * @param  list<string>  $resolvedIps  Every address DNS returned for the host.
     */
    public function isConnectionAllowed(string $host, array $resolvedIps): bool;
}
