<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Transport;

use JsonSerializable;

/**
 * Result of capability probes for a single proxy endpoint (plan §11.39 п.16).
 * Aggregates findings from TCP, UDP, and DNS probes into one immutable DTO.
 */
final readonly class CapabilityProbeResult implements JsonSerializable
{
    public const int SCHEMA_VERSION = 1;

    /**
     * @param  array<string, mixed>  $capabilities  Map of capability name → result.
     * @param  array<string, float>  $timingsMs  Named wall-clock timings in milliseconds.
     */
    public function __construct(
        public readonly bool $tcpReachable,
        public readonly ?bool $udpSupported,
        public readonly ?SocksDnsMode $dnsMode,
        public readonly ?DnsLeakResult $dnsLeak,
        public readonly array $capabilities,
        public readonly array $timingsMs,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'tcpReachable' => $this->tcpReachable,
            'udpSupported' => $this->udpSupported,
            'dnsMode' => $this->dnsMode?->value,
            'dnsLeak' => $this->dnsLeak?->jsonSerialize(),
            'capabilities' => $this->capabilities,
            'timingsMs' => $this->timingsMs,
            'schemaVersion' => self::SCHEMA_VERSION,
        ];
    }

    public static function unreachable(array $timingsMs): self
    {
        return new self(
            tcpReachable: false,
            udpSupported: null,
            dnsMode: null,
            dnsLeak: null,
            capabilities: [],
            timingsMs: $timingsMs,
        );
    }
}
