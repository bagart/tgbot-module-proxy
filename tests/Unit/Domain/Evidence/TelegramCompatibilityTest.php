<?php

declare(strict_types=1);

use BAGArt\ProxyOperations\Domain\Evidence\DcProbeResult;
use BAGArt\ProxyOperations\Domain\Evidence\TelegramCompatibility;
use BAGArt\ProxyOperations\Domain\Evidence\TelegramFreshnessPolicy;
use BAGArt\ProxyOperations\Domain\Failure\FailureCode;

it('builds from DC results with all reachable', function (): void {
    $results = [
        DcProbeResult::reached(1, 45.0),
        DcProbeResult::reached(2, 38.0),
        DcProbeResult::reached(3, 52.0),
    ];

    $compat = TelegramCompatibility::fromDcResults(
        dcResults: $results,
        dcSetVersion: 1,
        transport: 'socks5',
        checkedAt: '2026-09-13T12:00:00+00:00',
    );

    expect($compat->reachable)->toBeTrue()
        ->and($compat->bestDcId)->toBe(1)
        ->and($compat->medianRttMs)->toBe(45.0)
        ->and($compat->dcResults)->toHaveCount(3)
        ->and($compat->classificationReason)->toBe('dc_connectivity_confirmed');
});

it('builds unreachable when no DC responds', function (): void {
    $compat = TelegramCompatibility::fromDcResults(
        dcResults: [
            DcProbeResult::failed(1, FailureCode::TcpRefused),
            DcProbeResult::failed(2, FailureCode::TcpRefused),
        ],
        dcSetVersion: 1,
        transport: 'socks5',
        checkedAt: '2026-09-13T12:00:00+00:00',
    );

    expect($compat->reachable)->toBeFalse()
        ->and($compat->bestDcId)->toBeNull()
        ->and($compat->classificationReason)->toBe('no_dc_reachable');
});

it('round-trips through JSON', function (): void {
    $compat = TelegramCompatibility::fromDcResults(
        dcResults: [DcProbeResult::reached(2, 30.0)],
        dcSetVersion: 1,
        transport: 'mtproto',
        checkedAt: '2026-09-13T12:00:00+00:00',
    );

    $restored = TelegramCompatibility::fromJson($compat->jsonSerialize());

    expect($restored)->toEqual($compat);
});

it('DcProbeResult round-trips through JSON', function (): void {
    $ok = DcProbeResult::reached(3, 22.5);
    expect(DcProbeResult::fromJson($ok->jsonSerialize()))->toEqual($ok);

    $fail = DcProbeResult::failed(4, FailureCode::TcpTimeout);
    expect(DcProbeResult::fromJson($fail->jsonSerialize()))->toEqual($fail);
});

it('TelegramFreshnessPolicy computes freshness correctly', function (): void {
    $policy = new TelegramFreshnessPolicy(freshnessTtlSeconds: 3600);
    $now = new DateTimeImmutable('2026-09-13T12:00:00+00:00');

    expect($policy->isFresh($now->modify('-3599 seconds'), $now))->toBeTrue()
        ->and($policy->isFresh($now->modify('-3601 seconds'), $now))->toBeFalse()
        ->and($policy->freshUntil($now)->getTimestamp())->toBe($now->getTimestamp() + 3600);
});
