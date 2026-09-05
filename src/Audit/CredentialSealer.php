<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Audit;

use BAGArt\ProxyOperations\Encryption\CredentialEncryptor;
use BAGArt\ProxyOperations\Encryption\EncryptedField;
use BAGArt\ProxyOperations\Wire\SealedCredentialPayload;
use InvalidArgumentException;
use RuntimeException;
use SensitiveParameter;

/**
 * Runtime-key sealing for task delivery (plan §11.35 п.5, §11.37 R6.5): the
 * application decrypts the workspace DEK envelope in-process and re-seals the
 * credential with a separate runtime key before it enters an AuditTaskV1. The
 * worker receives only the sealed payload — KEK/DEK material never leaves the
 * application (INV-004); plaintext exists solely inside method scope and never
 * reaches logs, exceptions, or the wire (INV-013).
 *
 * The GCM authentication tag travels appended to the ciphertext (the wire
 * contract carries only algId/ciphertext/nonce); the worker-side unsealer
 * splits the last 16 bytes back off.
 */
final class CredentialSealer
{
    public const string ALG_ID = 'aes-256-gcm';

    private const int NONCE_BYTES = 12;

    private const int KEY_BYTES = 32;

    private const int TAG_BYTES = 16;

    public function __construct(
        private readonly CredentialEncryptor $encryptor,
        private readonly string $runtimeKey, // raw binary, derived via runtimeKeyFromConfig()
        private readonly int $ttlSeconds,
    ) {
        if (strlen($runtimeKey) !== self::KEY_BYTES) {
            throw new InvalidArgumentException(sprintf('The audit delivery runtime key must be %d bytes of key material.', self::KEY_BYTES));
        }

        if ($ttlSeconds < 1) {
            throw new InvalidArgumentException('The sealed credential TTL must be at least one second.');
        }
    }

    /**
     * Binary runtime key resolved once from the config layer (env is consumed
     * exclusively there, per platform rule); falls back to the application key
     * in dev/test posture only.
     */
    public static function runtimeKeyFromConfig(): string
    {
        $material = trim((string) config('proxy-operations.audit.delivery.seal_key', ''));

        if ($material === '' && (bool) config('proxy-operations.audit.delivery.fallback_to_app_key', false)) {
            $material = trim((string) config('app.key', ''));
        }

        if ($material === '') {
            throw new RuntimeException('The audit delivery seal key is not configured: set PROXY_AUDIT_SEAL_KEY or enable fallback_to_app_key with a usable APP_KEY.');
        }

        return hash('sha256', $material, true);
    }

    public function ttlSeconds(): int
    {
        return $this->ttlSeconds;
    }

    /**
     * Decrypts the DEK envelope in-process and re-seals the plaintext with the
     * runtime key. The plaintext never leaves this method scope (INV-013).
     *
     * @throws RuntimeException When the DEK envelope fails authentication.
     */
    public function seal(int $tenantId, EncryptedField $envelope): SealedCredentialPayload
    {
        return $this->sealWithRuntimeKey($this->encryptor->decrypt($tenantId, $envelope));
    }

    /**
     * @param  string  $plaintext  Credential material; never logged, never
     *                             included in exceptions.
     */
    private function sealWithRuntimeKey(#[SensitiveParameter] string $plaintext): SealedCredentialPayload
    {
        $nonce = random_bytes(self::NONCE_BYTES);
        $tag = '';

        $ciphertext = openssl_encrypt($plaintext, CredentialEncryptor::ALGORITHM, $this->runtimeKey, OPENSSL_RAW_DATA, $nonce, $tag);

        if ($ciphertext === false || strlen($tag) !== self::TAG_BYTES) {
            throw new RuntimeException('The audit task credential could not be sealed.');
        }

        return new SealedCredentialPayload(
            algId: self::ALG_ID,
            ciphertext: base64_encode($ciphertext.$tag),
            nonce: base64_encode($nonce),
        );
    }
}
