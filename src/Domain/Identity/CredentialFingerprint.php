<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Domain\Identity;

use JsonSerializable;
use RuntimeException;
use SensitiveParameter;

/**
 * Stable fingerprint of proxy credentials: HMAC-SHA256 over normalized
 * credential fields with a caller-injected binary key. The fingerprint never
 * contains or exposes the source credentials (masked-by-default rule).
 */
final readonly class CredentialFingerprint implements JsonSerializable
{
    public const int SCHEMA_VERSION = 1;

    public function __construct(
        public readonly string $value,
    ) {}

    /**
     * Username is normalized (trimmed, lowercased); the password participates
     * as raw bytes. Fields are length-prefixed before hashing so different
     * field splits can never collide.
     *
     * @param  string  $binaryKey  Raw binary HMAC key injected by the caller; never hardcoded.
     * @param  string  $password  Never logged, never included in exceptions.
     */
    public static function fromUserPass(
        string $binaryKey,
        string $username,
        #[SensitiveParameter]
        string $password,
    ): self {
        return new self(hash_hmac('sha256', self::normalizeMessage([
            'username' => mb_strtolower(trim($username)),
            'password' => $password,
        ]), $binaryKey));
    }

    /**
     * @param  array<string,string>  $fields
     */
    private static function normalizeMessage(array $fields): string
    {
        $message = '';

        foreach ($fields as $label => $value) {
            $message .= pack('N', strlen($label)).$label.pack('N', strlen($value)).$value;
        }

        return $message;
    }

    /**
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'value' => $this->value,
            'schemaVersion' => self::SCHEMA_VERSION,
        ];
    }

    /**
     * @param  array<string,mixed>  $data
     *
     * @throws RuntimeException If the format is not recognized.
     */
    public static function fromJson(array $data): self
    {
        return match ($data['schemaVersion'] ?? self::SCHEMA_VERSION) {
            self::SCHEMA_VERSION => self::fromJsonV1($data),
            default => throw new RuntimeException('Unsupported CredentialFingerprint schemaVersion: '.var_export($data['schemaVersion'], true)),
        };
    }

    /**
     * @param  array<string,mixed>  $data
     */
    private static function fromJsonV1(array $data): self
    {
        $value = (string) $data['value'];

        if (preg_match('/^[0-9a-f]{64}$/', $value) !== 1) {
            throw new RuntimeException('CredentialFingerprint payload has an invalid value.');
        }

        return new self($value);
    }
}
