<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Domain\Identity;

use JsonSerializable;
use RuntimeException;

/**
 * Canonical network endpoint identity: scheme + host + port (plan §11.2).
 *
 * The host is stored canonical (punycode / RFC 5952 IPv6, lowercase); the port
 * is always resolved (missing ports become the protocol default), so equality
 * is plain field comparison. Default ports are omitted from toString().
 */
final readonly class EndpointIdentity implements JsonSerializable
{
    public const int SCHEMA_VERSION = 1;

    public function __construct(
        public readonly string $host,
        public readonly int $port,
        public readonly ProxyProtocol $protocol,
    ) {
    }

    public function equals(self $other): bool
    {
        return $this->host === $other->host
            && $this->port === $other->port
            && $this->protocol === $other->protocol;
    }

    public function toString(): string
    {
        $host = str_contains($this->host, ':') ? "[{$this->host}]" : $this->host;
        $portSuffix = $this->port === $this->protocol->defaultPort() ? '' : ":{$this->port}";

        return "{$this->protocol->value}://{$host}{$portSuffix}";
    }

    public function __toString(): string
    {
        return $this->toString();
    }

    /**
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'host' => $this->host,
            'port' => $this->port,
            'protocol' => $this->protocol->value,
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
            default => throw new RuntimeException('Unsupported EndpointIdentity schemaVersion: '.var_export($data['schemaVersion'], true)),
        };
    }

    /**
     * @param  array<string,mixed>  $data
     */
    private static function fromJsonV1(array $data): self
    {
        return new self(
            host: (string) $data['host'],
            port: (int) $data['port'],
            protocol: ProxyProtocol::from((string) $data['protocol']),
        );
    }
}
