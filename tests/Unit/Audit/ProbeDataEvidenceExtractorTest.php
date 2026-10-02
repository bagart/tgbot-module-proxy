<?php

declare(strict_types=1);

use BAGArt\ProxyOperations\Audit\ProbeDataEvidenceExtractor;
use BAGArt\ProxyOperations\Domain\Evidence\BandwidthEvidence;
use BAGArt\ProxyOperations\Domain\Evidence\DnsEvidence;
use BAGArt\ProxyOperations\Domain\Evidence\EvidenceApplicability;
use BAGArt\ProxyOperations\Domain\Evidence\EvidenceType;
use BAGArt\ProxyOperations\Domain\Evidence\HttpEvidence;
use BAGArt\ProxyOperations\Domain\Evidence\TelegramEvidence;
use BAGArt\ProxyOperations\Domain\Evidence\UdpEvidence;
use BAGArt\ProxyOperations\Domain\Failure\FailureCode;
use BAGArt\ProxyOperations\Domain\Failure\FailureTaxonomy;
use BAGArt\ProxyOperations\Domain\Failure\ProxyFailure;
use BAGArt\ProxyOperations\Domain\Identity\ProxyProtocol;

function probeExtractor(): ProbeDataEvidenceExtractor
{
    return new ProbeDataEvidenceExtractor();
}

it('maps latency samples into BandwidthEvidence records', function (): void {
    $evidence = probeExtractor()->extract([
        'latency_series' => [
            ['latency_ms' => 120.5, 'bytes_transferred' => 1024, 'measured_at' => '2026-08-30T10:00:00+00:00'],
            ['latency_ms' => 180.0, 'bytes_transferred' => 1024, 'measured_at' => '2026-08-30T10:00:01+00:00'],
        ],
    ], ProxyProtocol::Socks5);

    expect($evidence)->toHaveCount(2)
        ->and($evidence[0])->toBeInstanceOf(BandwidthEvidence::class)
        ->and($evidence[0]->durationMs)->toBe(120.5)
        ->and($evidence[0]->bytesTransferred)->toBe(1024)
        ->and($evidence[0]->measuredAt->getTimestamp())->toBe(strtotime('2026-08-30T10:00:00+00:00'))
        ->and($evidence[1]->durationMs)->toBe(180.0);
});

it('maps http probe records into HttpEvidence with raw header flags', function (): void {
    $evidence = probeExtractor()->extract([
        'http_liveness' => [
            [
                'succeeded' => true,
                'status_code' => 200,
                'content_length' => 1284,
                'body_hash' => 'sha256:'.str_repeat('ab', 32),
                'total_duration_ms' => 610.0,
                'via_header_present' => true,
                'xff_header_present' => false,
                'real_ip_exposed' => false,
            ],
        ],
    ], ProxyProtocol::Http);

    expect($evidence)->toHaveCount(1)
        ->and($evidence[0])->toBeInstanceOf(HttpEvidence::class)
        ->and($evidence[0]->succeeded)->toBeTrue()
        ->and($evidence[0]->statusCode)->toBe(200)
        ->and($evidence[0]->contentLengthBytes)->toBe(1284)
        ->and($evidence[0]->viaHeaderPresent)->toBeTrue()
        ->and($evidence[0]->xffHeaderPresent)->toBeFalse()
        ->and($evidence[0]->failureCode)->toBeNull();
});

it('maps udp and dns probe records into their dimension DTOs', function (): void {
    $evidence = probeExtractor()->extract([
        'udp_associate' => [
            ['associate_succeeded' => true, 'round_trip_ms' => 42.5],
        ],
        'dns_resolution' => [
            ['resolution_succeeded' => true, 'resolved_addresses' => ['1.2.3.4', '5.6.7.8'], 'resolution_duration_ms' => 30.0],
        ],
    ], ProxyProtocol::Socks5);

    expect($evidence[0])->toBeInstanceOf(UdpEvidence::class)
        ->and($evidence[0]->associateSucceeded)->toBeTrue()
        ->and($evidence[0]->roundTripMs)->toBe(42.5)
        ->and($evidence[1])->toBeInstanceOf(DnsEvidence::class)
        ->and($evidence[1]->resolvedAddresses)->toBe(['1.2.3.4', '5.6.7.8']);
});

it('drops HTTP and UDP evidence as not applicable for MTProto accesses', function (): void {
    $applicability = new EvidenceApplicability();

    expect($applicability->for(ProxyProtocol::Mtproto, EvidenceType::Http))->toBe(\BAGArt\ProxyOperations\Domain\Evidence\Applicability::NotApplicable)
        ->and($applicability->for(ProxyProtocol::Mtproto, EvidenceType::Udp))->toBe(\BAGArt\ProxyOperations\Domain\Evidence\Applicability::NotApplicable)
        ->and($applicability->for(ProxyProtocol::Mtproto, EvidenceType::Telegram))->toBe(\BAGArt\ProxyOperations\Domain\Evidence\Applicability::Required);

    $evidence = probeExtractor()->extract([
        'http_liveness' => [['succeeded' => true, 'status_code' => 200]],
        'udp_associate' => [['associate_succeeded' => true]],
        'telegram_dc_connectivity' => [
            ['reachable_dc_ids' => [1, 2], 'attempted_dc_ids' => [1, 2], 'dc_set_version' => 'v3'],
        ],
    ], ProxyProtocol::Mtproto);

    expect($evidence)->toHaveCount(1)
        ->and($evidence[0])->toBeInstanceOf(TelegramEvidence::class)
        ->and($evidence[0]->reachableDcIds)->toBe([1, 2])
        ->and($evidence[0]->dcSetVersion)->toBe('v3');
});

it('keeps HTTP and DNS evidence for SOCKS5 accesses (optional dimensions apply)', function (): void {
    $evidence = probeExtractor()->extract([
        'http_liveness' => [['succeeded' => true, 'status_code' => 200]],
        'dns_resolution' => [['resolution_succeeded' => true, 'resolved_addresses' => ['1.2.3.4']]],
    ], ProxyProtocol::Socks5);

    expect(count($evidence))->toBe(2);
});

it('ignores unknown probe types for forward compatibility', function (): void {
    $evidence = probeExtractor()->extract([
        'quic_handshake_v9' => [['succeeded' => true]],
        'http_liveness' => [['succeeded' => true, 'status_code' => 200]],
    ], ProxyProtocol::Socks5);

    expect($evidence)->toHaveCount(1)
        ->and($evidence[0])->toBeInstanceOf(HttpEvidence::class);
});

it('carries failure codes from probe records into the evidence DTOs', function (): void {
    $evidence = probeExtractor()->extract([
        'http_liveness' => [['succeeded' => false, 'failure_code' => FailureCode::Target5xx->value]],
    ], ProxyProtocol::Http);

    expect($evidence[0]->failureCode)->toBe(FailureCode::Target5xx)
        ->and($evidence[0]->succeeded)->toBeFalse();
});

it('synthesizes dimension evidence from proxy failures', function (): void {
    $taxonomy = new FailureTaxonomy();

    $evidence = probeExtractor()->extractFailures([
        new ProxyFailure($taxonomy->descriptor(FailureCode::TcpTimeout), ['latency_ms' => 1500]),
        new ProxyFailure($taxonomy->descriptor(FailureCode::AuthFailure)),
    ]);

    expect($evidence)->toHaveCount(1)
        ->and($evidence[0])->toBeInstanceOf(\BAGArt\ProxyOperations\Domain\Evidence\TcpEvidence::class)
        ->and($evidence[0]->connectSucceeded)->toBeFalse()
        ->and($evidence[0]->latencyMs)->toBe(1500)
        ->and($evidence[0]->failureCode)->toBe(FailureCode::TcpTimeout);
});
