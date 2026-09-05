<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Encryption;

use BAGArt\ProxyOperations\Models\ProxyWorkspaceDek;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\UniqueConstraintViolationException;
use RuntimeException;
use SensitiveParameter;

/**
 * Envelope encryption for credential secrets (plan §§10.12 п.13, 11.23,
 * INV-004/007): a per-workspace DEK seals field values with AES-256-GCM and is
 * itself stored only KEK-wrapped in `proxy_workspace_deks`. Application-layer
 * only — the parser never encrypts (INV-007) and the checker worker never
 * receives this service or any key material (INV-004). Plaintext exists solely
 * inside method scope; it never reaches logs, exceptions, or serialized state.
 */
final class CredentialEncryptor
{
    public const string ALGORITHM = 'aes-256-gcm';

    private const int NONCE_BYTES = 12;

    private const int KEY_BYTES = 32;

    private const int TAG_BYTES = 16;

    public function __construct(
        private readonly KekProvider $keks,
        private readonly Repository $config,
    ) {
        $configuredAlgorithm = (string) $config->get('proxy-operations.encryption.algorithm', self::ALGORITHM);

        if ($configuredAlgorithm !== self::ALGORITHM) {
            throw new RuntimeException(sprintf('Unsupported credential envelope algorithm "%s"; only "%s" is implemented.', $configuredAlgorithm, self::ALGORITHM));
        }
    }

    public function encrypt(int $tenantId, #[SensitiveParameter] string $plaintext): EncryptedField
    {
        return $this->seal($plaintext, $this->workspaceDek($tenantId), $this->keks->currentVersion());
    }

    /**
     * @throws RuntimeException When authentication fails or no DEK row exists.
     */
    public function decrypt(int $tenantId, EncryptedField $field): string
    {
        $dek = $this->unwrappedWorkspaceDek($tenantId);
        $tag = $field->tag;

        $plaintext = openssl_decrypt(
            $field->ciphertext,
            self::ALGORITHM,
            $dek,
            OPENSSL_RAW_DATA,
            $field->nonce,
            $tag,
        );

        if ($plaintext === false) {
            throw new RuntimeException('Credential envelope failed authentication.');
        }

        return $plaintext;
    }

    /**
     * KEK rotation (plan §11.23): unwraps the workspace DEK with the historical
     * KEK recorded on its row and re-wraps it with the current one. Field
     * envelopes stay untouched — re-encrypting every secret is not required.
     */
    public function rewrapDek(int $tenantId): void
    {
        $row = $this->dekRow($tenantId);

        if ($row === null) {
            throw new RuntimeException(sprintf('No workspace DEK exists for tenant %d.', $tenantId));
        }

        $currentVersion = $this->keks->currentVersion();

        if ($row->key_version === $currentVersion) {
            return;
        }

        $dek = $this->unwrapDekEnvelope(EncryptedField::fromJson($row->wrapped_dek));

        $row->forceFill([
            'wrapped_dek' => $this->seal($dek, $this->keks->keyFor($currentVersion), $currentVersion)->jsonSerialize(),
            'key_version' => $currentVersion,
            'rotated_at' => now(),
        ])->save();
    }

    /**
     * @param  string  $plaintext  Key material when sealing a DEK, secret when
     *                             sealing a field value; never logged.
     */
    private function seal(#[SensitiveParameter] string $plaintext, string $key, string $keyVersion): EncryptedField
    {
        $nonce = random_bytes(self::NONCE_BYTES);
        $tag = '';

        $ciphertext = openssl_encrypt($plaintext, self::ALGORITHM, $key, OPENSSL_RAW_DATA, $nonce, $tag);

        if ($ciphertext === false || strlen($tag) !== self::TAG_BYTES) {
            throw new RuntimeException('The credential envelope could not be sealed.');
        }

        return new EncryptedField($keyVersion, self::ALGORITHM, $nonce, $ciphertext, $tag);
    }

    private function workspaceDek(int $tenantId): string
    {
        $existing = $this->dekRow($tenantId);

        if ($existing !== null) {
            return $this->unwrapDekEnvelope(EncryptedField::fromJson($existing->wrapped_dek));
        }

        $dek = random_bytes(self::KEY_BYTES);
        $version = $this->keks->currentVersion();

        try {
            ProxyWorkspaceDek::query()->create([
                'tenant_id' => $tenantId,
                'wrapped_dek' => $this->seal($dek, $this->keks->keyFor($version), $version)->jsonSerialize(),
                'key_version' => $version,
                'created_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return $this->unwrappedWorkspaceDek($tenantId);
        }

        return $dek;
    }

    private function unwrappedWorkspaceDek(int $tenantId): string
    {
        $row = $this->dekRow($tenantId);

        if ($row === null) {
            throw new RuntimeException(sprintf('No workspace DEK exists for tenant %d.', $tenantId));
        }

        return $this->unwrapDekEnvelope(EncryptedField::fromJson($row->wrapped_dek));
    }

    private function unwrapDekEnvelope(EncryptedField $wrapped): string
    {
        $tag = $wrapped->tag;

        $dek = openssl_decrypt(
            $wrapped->ciphertext,
            self::ALGORITHM,
            $this->keks->keyFor($wrapped->keyVersion),
            OPENSSL_RAW_DATA,
            $wrapped->nonce,
            $tag,
        );

        if ($dek === false || strlen($dek) !== self::KEY_BYTES) {
            throw new RuntimeException('The workspace DEK could not be unwrapped.');
        }

        return $dek;
    }

    private function dekRow(int $tenantId): ?ProxyWorkspaceDek
    {
        return ProxyWorkspaceDek::query()->where('tenant_id', $tenantId)->first();
    }
}
