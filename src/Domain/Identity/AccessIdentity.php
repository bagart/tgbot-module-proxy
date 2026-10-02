<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Domain\Identity;

use JsonSerializable;
use RuntimeException;

/**
 * The operationally checkable and issuable identity: endpoint + credentials
 * (plan §11.2). Leases, probe cache keys and the verified projection are all
 * addressed by AccessIdentity; health/lifecycle state lives on the access it
 * identifies.
 */
final readonly class AccessIdentity implements JsonSerializable
{
    public const int SCHEMA_VERSION = 1;

    public function __construct(
        public readonly EndpointIdentity $endpoint,
        public readonly CredentialFingerprint $credential,
    ) {
    }

    /**
     * Derived stable key: sha256 over the canonical endpoint URI and the
     * credential fingerprint.
     */
    public function key(): string
    {
        return hash('sha256', $this->endpoint->toString()."\x00".$this->credential->value);
    }

    /**
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'endpoint' => $this->endpoint->jsonSerialize(),
            'credential' => $this->credential->jsonSerialize(),
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
            default => throw new RuntimeException('Unsupported AccessIdentity schemaVersion: '.var_export($data['schemaVersion'], true)),
        };
    }

    /**
     * @param  array<string,mixed>  $data
     */
    private static function fromJsonV1(array $data): self
    {
        return new self(
            endpoint: EndpointIdentity::fromJson((array) $data['endpoint']),
            credential: CredentialFingerprint::fromJson((array) $data['credential']),
        );
    }
}
