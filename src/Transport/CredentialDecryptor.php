<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Transport;

use BAGArt\ProxyOperations\Wire\SealedCredentialPayload;

/**
 * Decrypts a sealed credential envelope. Production: AES-256-GCM via
 * the DEK stored in proxy_workspace_deks (T09). Test seam: injectable.
 */
interface CredentialDecryptor
{
    /**
     * Decrypt the sealed envelope and return the plaintext secret.
     */
    public function decrypt(SealedCredentialPayload $sealed): string;
}
