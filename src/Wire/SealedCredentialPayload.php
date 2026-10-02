<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Wire;

use JsonSerializable;
use RuntimeException;

/**
 * Short-lived sealed credential envelope delivered to the worker instead of a
 * reference (plan §11.35 п.5, variant A). Encrypted with a separate runtime
 * key, TTL = job TTL; plaintext credentials never exist in this contract.
 *
 * Ciphertext and nonce travel base64-encoded; no algorithm negotiation happens
 * on the wire — `algId` selects the unseal routine on the worker side.
 */
final readonly class SealedCredentialPayload implements JsonSerializable
{
    public const int SCHEMA_VERSION = 1;

    public function __construct(
        public readonly string $algId,
        public readonly string $ciphertext,
        public readonly string $nonce,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'algId' => $this->algId,
            'ciphertext' => $this->ciphertext,
            'nonce' => $this->nonce,
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
            default => throw new RuntimeException('Unsupported SealedCredentialPayload schemaVersion: '.var_export($data['schemaVersion'], true)),
        };
    }

    /**
     * @param  array<string,mixed>  $data
     */
    private static function fromJsonV1(array $data): self
    {
        return new self(
            algId: (string) $data['algId'],
            ciphertext: (string) $data['ciphertext'],
            nonce: (string) $data['nonce'],
        );
    }
}
