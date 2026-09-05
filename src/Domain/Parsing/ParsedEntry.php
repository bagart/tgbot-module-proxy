<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Domain\Parsing;

use BAGArt\ProxyOperations\Domain\Identity\CredentialKind;
use JsonSerializable;

/**
 * A single successfully parsed proxy entry.
 *
 * Holds raw host/port/scheme before canonicalization (T12 responsibility).
 * The `secret` field is plaintext by construction and MUST NEVER appear in
 * serialized output (INV-007).
 */
final readonly class ParsedEntry implements JsonSerializable
{
    public const int SCHEMA_VERSION = 1;

    public function __construct(
        public readonly string $scheme,
        public readonly string $host,
        public readonly int $port,
        public readonly ?string $username,
        public readonly ?string $secret,
        public readonly CredentialKind $credentialKind,
        public readonly int $sourceLine,
        public readonly ?string $originalHost,
    ) {}

    public function jsonSerialize(): array
    {
        return [
            'scheme' => $this->scheme,
            'host' => $this->host,
            'port' => $this->port,
            'username' => $this->username,
            'credentialKind' => $this->credentialKind->value,
            'sourceLine' => $this->sourceLine,
            'originalHost' => $this->originalHost,
            'schemaVersion' => self::SCHEMA_VERSION,
        ];
    }

    public static function fromJson(array $data): self
    {
        $schemaVersion = $data['schemaVersion'] ?? 1;

        return match ($schemaVersion) {
            1 => self::fromJsonV1($data),
            default => throw new \RuntimeException("Unsupported ParsedEntry schemaVersion: {$schemaVersion}"),
        };
    }

    private static function fromJsonV1(array $data): self
    {
        return new self(
            scheme: (string) $data['scheme'],
            host: (string) $data['host'],
            port: (int) $data['port'],
            username: $data['username'] ?? null,
            secret: null,
            credentialKind: CredentialKind::from($data['credentialKind']),
            sourceLine: (int) $data['sourceLine'],
            originalHost: $data['originalHost'] ?? null,
        );
    }
}
