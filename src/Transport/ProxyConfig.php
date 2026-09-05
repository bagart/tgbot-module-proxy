<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Transport;

use BAGArt\ProxyOperations\Domain\Identity\ProxyProtocol;
use InvalidArgumentException;
use JsonSerializable;
use RuntimeException;

/**
 * Transport-layer proxy configuration aggregate (plan §11.4–11.5).
 *
 * Carries connection parameters for each protocol family without leaking
 * domain entities (INV-011). Transport adapters consume this DTO; tool
 * implementations receive ProbeExecutionContext instead.
 */
final readonly class ProxyConfig implements JsonSerializable
{
    public const int SCHEMA_VERSION = 1;

    /**
     * @param  non-empty-string  $host
     * @param  int<1, 65535>  $port
     */
    public function __construct(
        public readonly ProxyProtocol $scheme,
        public readonly string $host,
        public readonly int $port,
        public readonly ?ProxyCredentialRef $credential,
        public readonly TlsOptions $tls,
        public readonly TransportOptions $transportOptions,
    ) {
        if ($this->host === '') {
            throw new InvalidArgumentException('ProxyConfig host must not be empty.');
        }

        if ($this->port < 1 || $this->port > 65535) {
            throw new InvalidArgumentException('ProxyConfig port must be within 1..65535.');
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'scheme' => $this->scheme->value,
            'host' => $this->host,
            'port' => $this->port,
            'credential' => $this->credential?->jsonSerialize(),
            'tls' => $this->tls->jsonSerialize(),
            'transportOptions' => $this->transportJson(),
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
            default => throw new RuntimeException('Unsupported ProxyConfig schemaVersion: '.var_export($data['schemaVersion'], true)),
        };
    }

    /**
     * @param  array<string,mixed>  $data
     */
    private static function fromJsonV1(array $data): self
    {
        $transportData = (array) ($data['transportOptions'] ?? []);
        $transportType = (string) ($transportData['type'] ?? '');

        $transportOptions = match ($transportType) {
            'socks' => SocksOptions::fromJson($transportData),
            'http_connect' => HttpConnectOptions::fromJson($transportData),
            'mtproto' => MtprotoOptions::fromJson($transportData),
            default => throw new RuntimeException("Unsupported TransportOptions type: {$transportType}"),
        };

        return new self(
            scheme: ProxyProtocol::from((string) $data['scheme']),
            host: (string) $data['host'],
            port: (int) $data['port'],
            credential: isset($data['credential']) ? ProxyCredentialRef::fromJson((array) $data['credential']) : null,
            tls: TlsOptions::fromJson((array) ($data['tls'] ?? [])),
            transportOptions: $transportOptions,
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function transportJson(): array
    {
        $type = match (true) {
            $this->transportOptions instanceof SocksOptions => 'socks',
            $this->transportOptions instanceof HttpConnectOptions => 'http_connect',
            $this->transportOptions instanceof MtprotoOptions => 'mtproto',
            default => throw new RuntimeException('Unknown TransportOptions class: '.get_class($this->transportOptions)),
        };

        return ['type' => $type] + $this->transportOptions->jsonSerialize();
    }
}
