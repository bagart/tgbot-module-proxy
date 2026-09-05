<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Audit;

use BAGArt\ProxyOperations\Domain\Evidence\Applicability;
use BAGArt\ProxyOperations\Domain\Evidence\BandwidthEvidence;
use BAGArt\ProxyOperations\Domain\Evidence\DimensionEvidence;
use BAGArt\ProxyOperations\Domain\Evidence\DnsEvidence;
use BAGArt\ProxyOperations\Domain\Evidence\EvidenceApplicability;
use BAGArt\ProxyOperations\Domain\Evidence\EvidenceType;
use BAGArt\ProxyOperations\Domain\Evidence\HttpEvidence;
use BAGArt\ProxyOperations\Domain\Evidence\JudgeEvidence;
use BAGArt\ProxyOperations\Domain\Evidence\TelegramEvidence;
use BAGArt\ProxyOperations\Domain\Evidence\TlsEvidence;
use BAGArt\ProxyOperations\Domain\Evidence\UdpEvidence;
use BAGArt\ProxyOperations\Domain\Evidence\TcpEvidence;
use BAGArt\ProxyOperations\Domain\Failure\FailureCode;
use BAGArt\ProxyOperations\Domain\Failure\ProxyFailure;
use BAGArt\ProxyOperations\Domain\Identity\ProxyProtocol;
use BAGArt\ProxyOperations\Domain\Probe\ProbeType;
use DateTimeImmutable;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Maps AuditResultV1::probeData records (OD-5) into T89 evidence DTOs
 * (plan §§11.7, 11.35 п.9). Each probeData key is a probe type; each record
 * a flat raw-observation payload already restricted to the shared-cache value
 * allowlist at the wire boundary.
 *
 * Mapping (documented deviation from the spec's DTO list — the T89 DTO set
 * has no TcpEvidence-producing probe yet, and latency samples are timing
 * measurements, hence bandwidth-dimension records):
 *   http_liveness, header_marker, anonymity_headers → HttpEvidence
 *   latency_series, bandwidth_transfer              → BandwidthEvidence
 *   udp_associate                                   → UdpEvidence
 *   dns_resolution                                  → DnsEvidence
 *   telegram_dc_connectivity, mtproto_handshake     → TelegramEvidence
 *   unknown probe types                             → ignored (forward compat)
 *
 * Evidence whose EvidenceType is NotApplicable for the access protocol
 * (EvidenceApplicability: MTProto → HTTP/UDP/DNS/Judge) is dropped here so
 * it can never reach — and never block — the health pipeline (§11.35 п.9).
 */
final class ProbeDataEvidenceExtractor
{
    public function __construct(
        private readonly EvidenceApplicability $applicability = new EvidenceApplicability,
    ) {}

    /**
     * @param  array<string, list<array<string,mixed>>>  $probeData
     * @return list<DimensionEvidence>
     */
    public function extract(array $probeData, ProxyProtocol $protocol): array
    {
        $evidence = [];

        foreach ($probeData as $probeTypeName => $records) {
            $probeType = ProbeType::tryFrom((string) $probeTypeName);

            if ($probeType === null) {
                continue; // Unknown probe type — forward compatibility.
            }

            foreach ($records as $record) {
                $built = $this->build($probeType, self::record($record));

                if ($built === null) {
                    continue;
                }

                if ($this->applicability->for($protocol, $built->type()) === Applicability::NotApplicable) {
                    continue;
                }

                $evidence[] = $built;
            }
        }

        return $evidence;
    }

    /**
     * Synthesizes dimension evidence from proxy-side failures so negative
     * signals reach the health pipeline (§11.7 — failure evidence counts).
     * Only codes with a natural dimension DTO are mapped; the rest stay in
     * the observation row and the attempt result_code.
     *
     * @param  list<ProxyFailure>  $observations
     * @return list<DimensionEvidence>
     */
    public function extractFailures(array $observations): array
    {
        $evidence = [];

        foreach ($observations as $failure) {
            $at = $this->measuredAt($failure->context);
            $code = $failure->descriptor->code;

            $built = match ($code) {
                FailureCode::TcpTimeout, FailureCode::TcpRefused => new TcpEvidence(
                    connectSucceeded: false,
                    latencyMs: self::nullableInt($failure->context['latency_ms'] ?? $failure->context['latencyMs'] ?? null),
                    failureCode: $code,
                    measuredAt: $at,
                ),
                FailureCode::TlsFailure => new TlsEvidence(
                    handshakeSucceeded: false,
                    tlsVersion: null,
                    handshakeDurationMs: null,
                    failureCode: $code,
                    measuredAt: $at,
                ),
                FailureCode::JudgeUnavailable, FailureCode::JudgeInconsistent => new JudgeEvidence(
                    judgeId: (string) ($failure->context['judge_id'] ?? $failure->context['judgeId'] ?? ''),
                    reachable: $code === FailureCode::JudgeInconsistent,
                    verdictConsistent: false,
                    responseDurationMs: null,
                    failureCode: $code,
                    measuredAt: $at,
                ),
                FailureCode::MtprotoHandshakeFailed => new TelegramEvidence(
                    reachableDcIds: [],
                    attemptedDcIds: array_values(array_map(intval(...), (array) ($failure->context['attempted_dc_ids'] ?? []))),
                    dcSetVersion: (string) ($failure->context['dc_set_version'] ?? 'unknown'),
                    medianRttMs: null,
                    failureCode: $code,
                    measuredAt: $at,
                ),
                default => null,
            };

            if ($built !== null) {
                $evidence[] = $built;
            }
        }

        return $evidence;
    }

    private function build(ProbeType $probeType, array $record): ?DimensionEvidence
    {
        $at = $this->measuredAt($record);
        $failureCode = FailureCode::tryFrom((string) ($record['failure_code'] ?? ''));

        return match ($probeType) {
            ProbeType::HttpLiveness,
            ProbeType::HeaderMarker,
            ProbeType::AnonymityHeaders => new HttpEvidence(
                succeeded: self::bool($record['succeeded'] ?? true),
                statusCode: self::nullableInt($record['status_code'] ?? null),
                contentLengthBytes: self::nullableInt($record['content_length'] ?? null),
                bodyHashSha256: self::nullableString($record['body_hash'] ?? null),
                totalDurationMs: self::nullableFloat($record['total_duration_ms'] ?? null),
                viaHeaderPresent: self::bool($record['via_header_present'] ?? false),
                xffHeaderPresent: self::bool($record['xff_header_present'] ?? false),
                forwardedHeaderPresent: self::bool($record['forwarded_header_present'] ?? false),
                xRealIpHeaderPresent: self::bool($record['x_real_ip_header_present'] ?? false),
                realIpExposed: self::bool($record['real_ip_exposed'] ?? false),
                markerModified: self::bool($record['marker_modified'] ?? false),
                failureCode: $failureCode,
                measuredAt: $at,
            ),
            ProbeType::LatencySeries,
            ProbeType::BandwidthTransfer => new BandwidthEvidence(
                transferCompleted: self::bool($record['transfer_completed'] ?? true),
                bytesTransferred: (int) ($record['bytes_transferred'] ?? 0),
                durationMs: (float) ($record['duration_ms'] ?? $record['latency_ms'] ?? 0),
                failureCode: $failureCode,
                measuredAt: $at,
            ),
            ProbeType::UdpAssociate => new UdpEvidence(
                associateSucceeded: self::bool($record['associate_succeeded'] ?? true),
                roundTripMs: self::nullableFloat($record['round_trip_ms'] ?? null),
                failureCode: $failureCode,
                measuredAt: $at,
            ),
            ProbeType::DnsResolution => new DnsEvidence(
                resolutionSucceeded: self::bool($record['resolution_succeeded'] ?? true),
                resolvedAddresses: array_values(array_map(strval(...), (array) ($record['resolved_addresses'] ?? []))),
                resolutionDurationMs: self::nullableFloat($record['resolution_duration_ms'] ?? null),
                failureCode: $failureCode,
                measuredAt: $at,
            ),
            ProbeType::TelegramDcConnectivity,
            ProbeType::MtprotoHandshake => new TelegramEvidence(
                reachableDcIds: array_values(array_map(intval(...), (array) ($record['reachable_dc_ids'] ?? []))),
                attemptedDcIds: array_values(array_map(intval(...), (array) ($record['attempted_dc_ids'] ?? []))),
                dcSetVersion: (string) ($record['dc_set_version'] ?? 'unknown'),
                medianRttMs: self::nullableFloat($record['median_rtt_ms'] ?? null),
                failureCode: $failureCode,
                measuredAt: $at,
            ),
        };
    }

    /**
     * @return array<string,mixed>
     */
    private static function record(mixed $record): array
    {
        if (! is_array($record)) {
            throw new InvalidArgumentException('ProbeData records must be arrays.');
        }

        return $record;
    }

    /**
     * @param  array<string,mixed>  $record
     */
    private function measuredAt(array $record): DateTimeImmutable
    {
        $raw = $record['measured_at'] ?? null;

        if (is_string($raw) && $raw !== '') {
            try {
                return new DateTimeImmutable($raw);
            } catch (\Exception) {
                // fall through to ingest time
            }
        }

        return Carbon::now()->toDateTimeImmutable();
    }

    private static function bool(mixed $value): bool
    {
        return (bool) $value;
    }

    private static function nullableInt(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    private static function nullableFloat(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }

    private static function nullableString(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
