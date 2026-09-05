<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Models;

use BAGArt\ProxyOperations\Database\Factories\ProxyCredentialFactory;
use BAGArt\ProxyOperations\Domain\Identity\CredentialFingerprint;
use BAGArt\ProxyOperations\Domain\Identity\CredentialKind;
use BAGArt\ProxyOperations\Encryption\CredentialEncryptor;
use BAGArt\ProxyOperations\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;
use RuntimeException;
use SensitiveParameter;

/**
 * Credential profile (plan §11.3): protocol-specific secret material reduced
 * to a deterministic fingerprint, a masked representation and an encrypted
 * envelope. The plaintext is never persisted: it arrives through the
 * transient `secret` attribute, feeds the fingerprint/mask derivation at
 * create time, then is sealed into `secret_envelope` ({key_version,
 * algorithm, nonce, ciphertext, tag}, T09 envelope encryption) and discarded.
 *
 * INV-007: nothing here encrypts on behalf of parsing — the encryptor is the
 * application-layer service reached from the creating seam only.
 *
 * @property string $id
 * @property int $tenant_id
 * @property string|null $endpoint_id
 * @property CredentialKind $kind
 * @property string|null $username
 * @property array<string, mixed>|null $secret_envelope
 * @property string $fingerprint
 * @property string $masked_representation
 */
final class ProxyCredential extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'endpoint_id',
        'kind',
        'username',
        'secret',
    ];

    protected $hidden = [
        'secret_envelope',
        'secret',
    ];

    protected static function booted(): void
    {
        self::creating(function (self $credential): void {
            $plaintext = (string) ($credential->getAttribute('secret') ?? '');
            $credential->offsetUnset('secret');

            if ($plaintext === '') {
                throw new InvalidArgumentException(
                    'A proxy credential cannot be created without its secret material.',
                );
            }

            $endpoint = $credential->endpoint_id === null ? null : $credential->endpoint()->first();

            // Fingerprint/mask derive on plaintext before it is sealed; the
            // envelope is written by the T09 encryptor, never in clear form.
            $envelope = app(CredentialEncryptor::class)
                ->encrypt((int) $credential->getAttribute('tenant_id'), $plaintext)
                ->jsonSerialize();

            $credential->forceFill([
                'fingerprint' => self::fingerprint($credential->username, $plaintext),
                'masked_representation' => self::buildMask($credential->kind, $credential->username, $endpoint),
                'secret_envelope' => $envelope,
            ]);
        });
    }

    /**
     * Tenant-independent hex64 fingerprint over the canonical credential
     * payload (§11.37 R6.2): identical credentials share a fingerprint across
     * workspaces so the shared probe cache can be keyed without secrets.
     * MTProto secrets participate via the secret slot with an empty username.
     *
     * @param  string  $secret  Plaintext credential material; never logged,
     *                          never included in exceptions.
     */
    public static function fingerprint(
        ?string $username,
        #[SensitiveParameter]
        string $secret,
    ): string {
        return CredentialFingerprint::fromUserPass(self::fingerprintKey(), $username ?? '', $secret)->value;
    }

    /**
     * Binary HMAC key for CredentialFingerprint, derived from the configured
     * key (env read once at the config layer). Falls back to the application
     * key only when fallback_to_app_key is enabled (dev/test posture).
     */
    public static function fingerprintKey(): string
    {
        $key = trim((string) config('proxy-operations.encryption.fingerprint_key', ''));

        if ($key === '' && (bool) config('proxy-operations.encryption.fallback_to_app_key', false)) {
            $key = (string) config('app.key', '');
        }

        if ($key === '') {
            throw new RuntimeException('The proxy credential fingerprint key is not configured.');
        }

        return hash('sha256', $key, true);
    }

    /**
     * Masked representation of this credential; free of secret material by
     * construction and by test.
     */
    public function mask(): string
    {
        return $this->masked_representation;
    }

    private static function buildMask(CredentialKind $kind, ?string $username, ?ProxyEndpoint $endpoint): string
    {
        $identity = $endpoint === null ? '' : '@'.$endpoint->host.':'.$endpoint->port;

        if ($kind === CredentialKind::MtprotoSecret) {
            return 'sec***'.$identity;
        }

        $prefix = $username === null || $username === '' ? '?' : mb_substr(trim($username), 0, 2);

        return $prefix.'***:***'.$identity;
    }

    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(ProxyEndpoint::class);
    }

    /**
     * Access identities built on this credential profile (T05, plan §11.2).
     */
    public function accesses(): HasMany
    {
        return $this->hasMany(ProxyAccess::class);
    }

    protected function casts(): array
    {
        return [
            'kind' => CredentialKind::class,
            'secret_envelope' => 'array',
        ];
    }

    protected static function newFactory(): Factory
    {
        return ProxyCredentialFactory::new();
    }
}
