<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Domain\Evidence;

use JsonSerializable;

/**
 * Full Telegram probe result — aggregated from individual DC probes and/or
 * MTProto handshake (plan §11.35 п.10).
 *
 * Consumers: TelegramEvidence factory, DimensionalHealthEvaluator, VerifiedEligibilityPolicy.
 */
final readonly class TelegramCompatibility implements JsonSerializable
{
    public const int SCHEMA_VERSION = 1;

    /**
     * @param  list<DcProbeResult>  $dcResults  Per-DC probe outcomes.
     * @param  string  $supportedTransport  Transport that achieved connectivity.
     * @param  string|null  $classificationReason  Human-readable reason for the classification.
     */
    public function __construct(
        public bool $reachable,
        public string $supportedTransport,
        public array $dcResults,
        public ?int $bestDcId,
        public ?float $medianRttMs,
        public int $checkedDcSetVersion,
        public ?string $classificationReason,
        public string $checkedAt,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'reachable' => $this->reachable,
            'supportedTransport' => $this->supportedTransport,
            'dcResults' => array_map(
                static fn (DcProbeResult $r): array => $r->jsonSerialize(),
                $this->dcResults,
            ),
            'bestDcId' => $this->bestDcId,
            'medianRttMs' => $this->medianRttMs,
            'checkedDcSetVersion' => $this->checkedDcSetVersion,
            'classificationReason' => $this->classificationReason,
            'checkedAt' => $this->checkedAt,
            'schemaVersion' => self::SCHEMA_VERSION,
        ];
    }

    /**
     * @param  array<string,mixed>  $data
     */
    public static function fromJson(array $data): self
    {
        return new self(
            reachable: (bool) $data['reachable'],
            supportedTransport: (string) $data['supportedTransport'],
            dcResults: array_map(
                static fn (array $r): DcProbeResult => DcProbeResult::fromJson($r),
                (array) ($data['dcResults'] ?? []),
            ),
            bestDcId: isset($data['bestDcId']) ? (int) $data['bestDcId'] : null,
            medianRttMs: isset($data['medianRttMs']) ? (float) $data['medianRttMs'] : null,
            checkedDcSetVersion: (int) ($data['checkedDcSetVersion'] ?? 0),
            classificationReason: $data['classificationReason'] ?? null,
            checkedAt: (string) $data['checkedAt'],
        );
    }

    /**
     * Build a TelegramCompatibility from probe results.
     *
     * @param  list<DcProbeResult>  $dcResults
     */
    public static function fromDcResults(
        array $dcResults,
        int $dcSetVersion,
        string $transport,
        string $checkedAt,
    ): self {
        $reachable = array_values(array_filter(
            $dcResults,
            static fn (DcProbeResult $r): bool => $r->reachable,
        ));

        $bestDcId = $reachable !== [] ? $reachable[array_key_first($reachable)]->dcId : null;
        $rtts = array_map(static fn (DcProbeResult $r): float => $r->rttMs ?? 0.0, $reachable);
        sort($rtts);
        $medianRttMs = $rtts !== [] ? $rtts[(int) (count($rtts) / 2)] : null;

        $reason = match (true) {
            $reachable === [] => 'no_dc_reachable',
            count($reachable) >= 1 => 'dc_connectivity_confirmed',
            default => 'unknown',
        };

        return new self(
            reachable: $reachable !== [],
            supportedTransport: $transport,
            dcResults: $dcResults,
            bestDcId: $bestDcId,
            medianRttMs: $medianRttMs,
            checkedDcSetVersion: $dcSetVersion,
            classificationReason: $reason,
            checkedAt: $checkedAt,
        );
    }
}
