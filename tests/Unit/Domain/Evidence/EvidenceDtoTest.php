<?php

declare(strict_types=1);

use BAGArt\ProxyOperations\Domain\Evidence\BandwidthEvidence;
use BAGArt\ProxyOperations\Domain\Evidence\DimensionEvidence;
use BAGArt\ProxyOperations\Domain\Evidence\DnsEvidence;
use BAGArt\ProxyOperations\Domain\Evidence\EvidenceType;
use BAGArt\ProxyOperations\Domain\Evidence\HttpEvidence;
use BAGArt\ProxyOperations\Domain\Evidence\JudgeEvidence;
use BAGArt\ProxyOperations\Domain\Evidence\TcpEvidence;
use BAGArt\ProxyOperations\Domain\Evidence\TelegramEvidence;
use BAGArt\ProxyOperations\Domain\Evidence\TlsEvidence;
use BAGArt\ProxyOperations\Domain\Evidence\UdpEvidence;
use BAGArt\ProxyOperations\Domain\Failure\FailureCode;

function evidenceSample(EvidenceType $type, DateTimeImmutable $measuredAt): DimensionEvidence
{
    return match ($type) {
        EvidenceType::Tcp => new TcpEvidence(true, 42, null, $measuredAt),
        EvidenceType::Http => new HttpEvidence(true, 200, 1024, 'abc', 120.5, false, false, false, false, false, false, null, $measuredAt),
        EvidenceType::Tls => new TlsEvidence(true, 'TLSv1.3', 30.0, null, $measuredAt),
        EvidenceType::Dns => new DnsEvidence(true, ['203.0.113.7'], 15.0, null, $measuredAt),
        EvidenceType::Udp => new UdpEvidence(true, 55.5, null, $measuredAt),
        EvidenceType::Judge => new JudgeEvidence('judge-1', true, true, 80.0, null, $measuredAt),
        EvidenceType::Telegram => new TelegramEvidence([1, 2], [1, 2, 3], 'dc-set-v1', 90.0, null, $measuredAt),
        EvidenceType::Bandwidth => new BandwidthEvidence(true, 1048576, 1000.0, null, $measuredAt),
    };
}

it('reports its dimension and measurement time for every evidence type', function (EvidenceType $type): void {
    $measuredAt = new DateTimeImmutable('2026-08-26T12:00:00+00:00');
    $evidence = evidenceSample($type, $measuredAt);

    expect($evidence)->toBeInstanceOf(DimensionEvidence::class)
        ->and($evidence->type())->toBe($type)
        ->and($evidence->measuredAt())->toBe($measuredAt);
})->with(EvidenceType::cases());

it('keeps failure codes nullable for successful measurements', function (): void {
    $tcp = new TcpEvidence(true, 10, null, new DateTimeImmutable);

    expect($tcp->failureCode)->toBeNull()
        ->and($tcp->connectSucceeded)->toBeTrue();
});

it('carries the concrete failure code on failed measurements', function (): void {
    $tcp = new TcpEvidence(false, null, FailureCode::TcpTimeout, new DateTimeImmutable);

    expect($tcp->connectSucceeded)->toBeFalse()
        ->and($tcp->latencyMs)->toBeNull()
        ->and($tcp->failureCode)->toBe(FailureCode::TcpTimeout);
});
