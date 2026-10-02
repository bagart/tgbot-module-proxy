<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Transport;

use JsonSerializable;
use RuntimeException;

/**
 * TLS configuration for the proxy connection (plan §11.5).
 */
final readonly class TlsOptions implements JsonSerializable
{
    public const int SCHEMA_VERSION = 1;

    public function __construct(
        public readonly bool $verifyPeer = true,
        public readonly bool $allowSelfSigned = false,
        public readonly ?string $caBundlePath = null,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'verifyPeer' => $this->verifyPeer,
            'allowSelfSigned' => $this->allowSelfSigned,
            'caBundlePath' => $this->caBundlePath,
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
            default => throw new RuntimeException('Unsupported TlsOptions schemaVersion: '.var_export($data['schemaVersion'], true)),
        };
    }

    /**
     * @param  array<string,mixed>  $data
     */
    private static function fromJsonV1(array $data): self
    {
        return new self(
            verifyPeer: (bool) ($data['verifyPeer'] ?? true),
            allowSelfSigned: (bool) ($data['allowSelfSigned'] ?? false),
            caBundlePath: isset($data['caBundlePath']) ? (string) $data['caBundlePath'] : null,
        );
    }
}
