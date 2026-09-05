<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Tests\Fixtures;

use BAGArt\ProxyOperations\Transport\CredentialDecryptor;
use BAGArt\ProxyOperations\Wire\SealedCredentialPayload;
use RuntimeException;

/**
 * Worker-side runtime unsealer for tests: mirrors the CredentialSealer key
 * derivation (config-layer material hashed to 32 bytes) and splits the GCM
 * tag off the appended ciphertext. Never used in production wiring.
 */
final class RuntimeCredentialDecryptor implements CredentialDecryptor
{
    private const int TAG_BYTES = 16;

    public function decrypt(SealedCredentialPayload $sealed): string
    {
        $material = trim((string) config('proxy-operations.audit.delivery.seal_key', ''));

        if ($material === '' && (bool) config('proxy-operations.audit.delivery.fallback_to_app_key', false)) {
            $material = trim((string) config('app.key', ''));
        }

        if ($material === '') {
            throw new RuntimeException('The audit delivery seal key is not configured.');
        }

        $key = hash('sha256', $material, true);
        $ciphertext = base64_decode($sealed->ciphertext, true);
        $nonce = base64_decode($sealed->nonce, true);

        if ($ciphertext === false || $nonce === false || strlen($ciphertext) <= self::TAG_BYTES) {
            throw new RuntimeException('The sealed credential payload is malformed.');
        }

        $tag = substr($ciphertext, -self::TAG_BYTES);
        $body = substr($ciphertext, 0, -self::TAG_BYTES);

        $plaintext = openssl_decrypt($body, $sealed->algId, $key, OPENSSL_RAW_DATA, $nonce, $tag);

        if ($plaintext === false) {
            throw new RuntimeException('The sealed credential failed authentication.');
        }

        return $plaintext;
    }
}
