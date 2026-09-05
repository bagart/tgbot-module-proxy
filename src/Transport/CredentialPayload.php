<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Transport;

/**
 * Decrypted credential material, held only in memory for the duration of
 * a single probe execution. Never logged, never serialized, zeroized on
 * scope exit (plan §11.39 п.6, INV-013).
 *
 * This is a short-lived value object — it exists only within the scope of
 * one probe execution, never escapes to logs/exceptions/Redis.
 */
final readonly class CredentialPayload
{
    public function __construct(
        public readonly ?string $username,
        public readonly string $secret,
    ) {}
}
