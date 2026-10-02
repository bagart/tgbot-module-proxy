<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Domain\Evidence;

use BAGArt\ProxyOperations\Domain\Failure\FailureCode;
use JsonSerializable;

/**
 * Per-DC probe result — one entry in a TelegramCompatibility report.
 */
final readonly class DcProbeResult implements JsonSerializable
{
    public const int SCHEMA_VERSION = 1;

    public function __construct(
        public int $dcId,
        public bool $reachable,
        public ?float $rttMs,
        public ?FailureCode $failureCode,
    ) {
    }

    public static function reached(int $dcId, float $rttMs): self
    {
        return new self(dcId: $dcId, reachable: true, rttMs: $rttMs, failureCode: null);
    }

    public static function failed(int $dcId, FailureCode $code): self
    {
        return new self(dcId: $dcId, reachable: false, rttMs: null, failureCode: $code);
    }

    /**
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'dcId' => $this->dcId,
            'reachable' => $this->reachable,
            'rttMs' => $this->rttMs,
            'failureCode' => $this->failureCode?->value,
            'schemaVersion' => self::SCHEMA_VERSION,
        ];
    }

    /**
     * @param  array<string,mixed>  $data
     */
    public static function fromJson(array $data): self
    {
        return new self(
            dcId: (int) $data['dcId'],
            reachable: (bool) $data['reachable'],
            rttMs: isset($data['rttMs']) ? (float) $data['rttMs'] : null,
            failureCode: isset($data['failureCode']) ? FailureCode::from($data['failureCode']) : null,
        );
    }
}
