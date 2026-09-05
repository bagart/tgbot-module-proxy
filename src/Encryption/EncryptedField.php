<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Encryption;

use JsonSerializable;
use RuntimeException;

/**
 * Ciphertext envelope for one encrypted field value (plan §§10.12 п.13,
 * 11.23): serializes to exactly `{key_version, algorithm, nonce, ciphertext,
 * tag}`. Binary members travel base64-encoded on the wire; properties hold
 * raw bytes. Contains no clear secret material by construction.
 *
 * @see https://www.openssl.org/docs/manmaster/man3/EVP_aes_256_gcm.html
 */
final readonly class EncryptedField implements JsonSerializable
{
    public const int SCHEMA_VERSION = 1;

    public function __construct(
        public readonly string $keyVersion,
        public readonly string $algorithm,
        public readonly string $nonce,
        public readonly string $ciphertext,
        public readonly string $tag,
    ) {}

    /**
     * @return array{key_version: string, algorithm: string, nonce: string, ciphertext: string, tag: string}
     */
    public function jsonSerialize(): array
    {
        return [
            'key_version' => $this->keyVersion,
            'algorithm' => $this->algorithm,
            'nonce' => base64_encode($this->nonce),
            'ciphertext' => base64_encode($this->ciphertext),
            'tag' => base64_encode($this->tag),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws RuntimeException If the format is not recognized or malformed.
     */
    public static function fromJson(array $data): self
    {
        return match ($data['schemaVersion'] ?? self::SCHEMA_VERSION) {
            self::SCHEMA_VERSION => self::fromJsonV1($data),
            default => throw new RuntimeException('Unsupported EncryptedField schemaVersion: '.var_export($data['schemaVersion'], true)),
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function fromJsonV1(array $data): self
    {
        return new self(
            keyVersion: (string) ($data['key_version'] ?? ''),
            algorithm: (string) ($data['algorithm'] ?? ''),
            nonce: self::decodeMember((string) ($data['nonce'] ?? ''), 'nonce'),
            ciphertext: self::decodeMember((string) ($data['ciphertext'] ?? ''), 'ciphertext'),
            tag: self::decodeMember((string) ($data['tag'] ?? ''), 'tag'),
        );
    }

    private static function decodeMember(string $value, string $member): string
    {
        $decoded = base64_decode($value, true);

        if ($decoded === false || $decoded === '') {
            throw new RuntimeException(sprintf('Malformed base64 payload in EncryptedField member "%s".', $member));
        }

        return $decoded;
    }
}
