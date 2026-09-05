<?php

declare(strict_types=1);

use BAGArt\ProxyOperations\Domain\Cache\SharedCacheValue;
use BAGArt\ProxyOperations\Domain\Cache\SharedCacheValueKind;

function sharablePayload(): array
{
    return [
        'status' => 200,
        'content_length' => 1024,
        'body_hash' => str_repeat('c', 64),
        'bytes_received' => 1024,
        'total_time_ms' => 120,
    ];
}

it('accepts every allowlisted raw-measurement kind', function (): void {
    foreach (SharedCacheValueKind::cases() as $kind) {
        $value = new SharedCacheValue($kind, ['measured' => 1]);

        expect($value->kind)->toBe($kind);
    }
});

it('rejects payloads carrying tenant interpretation keys', function (string $key): void {
    new SharedCacheValue(SharedCacheValueKind::HttpMeasurement, [$key => 95]);
})->with([
    'health' => ['health_score'],
    'score' => ['anonymity_score'],
    'lifecycle' => ['lifecycle_state'],
    'verified' => ['verified'],
    'state' => ['access_state'],
    'quarantine' => ['quarantined'],
    'tier' => ['anonymity_tier'],
    'usable' => ['telegram_usable'],
])->throws(RuntimeException::class, 'tenant interpretation');

it('rejects payloads carrying secret-bearing keys', function (): void {
    new SharedCacheValue(SharedCacheValueKind::ExitIpObservation, ['proxy_authorization' => 'Basic ...']);
})->throws(RuntimeException::class, 'secret-bearing');

it('rejects non-scalar payload values', function (): void {
    new SharedCacheValue(SharedCacheValueKind::TimingMeasurement, ['timings' => [1, 2, 3]]);
})->throws(RuntimeException::class);

it('round-trips through JSON', function (): void {
    $value = new SharedCacheValue(SharedCacheValueKind::MarkerResult, [
        'marker_match' => true,
        'marker_modified' => false,
    ]);

    $restored = SharedCacheValue::fromJson(json_decode(json_encode($value), true));

    expect($restored)->toEqual($value);
});

it('accepts the negative-probe-result payload keys', function (): void {
    $value = new SharedCacheValue(SharedCacheValueKind::NegativeProbeResult, [
        'failure_code' => 'CONNECT_TIMEOUT',
        'checked_at_ms' => 1_800_000_000_000,
    ]);

    expect($value->kind)->toBe(SharedCacheValueKind::NegativeProbeResult)
        ->and($value->payload['failure_code'])->toBe('CONNECT_TIMEOUT');
});

it('round-trips the negative-probe-result kind through JSON', function (): void {
    $value = new SharedCacheValue(SharedCacheValueKind::NegativeProbeResult, [
        'failure_code' => 'AUTH_FAILURE',
        'checked_at_ms' => 1_800_000_000_000,
    ]);

    $restored = SharedCacheValue::fromJson(json_decode(json_encode($value), true));

    expect($restored)->toEqual($value);
});

it('rejects kinds that are not on the allowlist during deserialization', function (): void {
    SharedCacheValue::fromJson([
        'schemaVersion' => 1,
        'kind' => 'health_interpretation',
        'payload' => [],
    ]);
})->throws(RuntimeException::class, 'allowlist');

it('rejects unknown schema versions', function (): void {
    SharedCacheValue::fromJson(['schemaVersion' => 99, 'kind' => 'marker_result']);
})->throws(RuntimeException::class, 'Unsupported SharedCacheValue schemaVersion');
