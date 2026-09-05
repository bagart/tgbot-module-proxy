<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Domain\Snapshot;

/**
 * Trust tier of a judge node (plan §11.8). HMAC protection applies ONLY to
 * self-hosted judges; public judges are never cryptographically trusted —
 * their trust is HTTPS + multi-judge agreement + marker probes + HTTP-vs-HTTPS
 * cross-checks.
 */
enum JudgeTrustTier: string
{
    case SelfHosted = 'self_hosted';
    case PublicHttps = 'public_https';

    /**
     * Whether observations signed by this judge carry a verifiable HMAC.
     * Codifies plan §11.8: HMAC trust only for self-hosted judges.
     */
    public function isHmacProtected(): bool
    {
        return $this === self::SelfHosted;
    }
}
