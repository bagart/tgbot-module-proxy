<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Transport;

use BAGArt\ProxyOperations\Wire\SealedCredentialPayload;

/**
 * Unseals a SealedCredentialPayload from AuditTaskV1 into ephemeral
 * CredentialPayload. The unseal happens once per probe execution,
 * scoped to the worker process (plan §11.39 п.6).
 *
 * CredentialPayload lives only in memory; caller must use it within the
 * probe scope.
 */
final readonly class CredentialUnsealer
{
    public function __construct(
        private readonly CredentialDecryptor $decryptor,
    ) {
    }

    public function unseal(SealedCredentialPayload $sealed): CredentialPayload
    {
        $plaintext = $this->decryptor->decrypt($sealed);

        return new CredentialPayload(
            username: null,
            secret: $plaintext,
        );
    }
}
