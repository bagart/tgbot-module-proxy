<?php

declare(strict_types=1);

use BAGArt\ProxyOperations\Audit\CachePolicy;
use BAGArt\ProxyOperations\Audit\LaravelCacheProbeCache;
use BAGArt\ProxyOperations\Domain\Cache\ProbeCacheKeyV3;
use BAGArt\ProxyOperations\Domain\Cache\SharedCacheValue;
use BAGArt\ProxyOperations\Domain\Cache\SharedCacheValueKind;
use BAGArt\ProxyOperations\Domain\Identity\CredentialFingerprint;
use BAGArt\ProxyOperations\Domain\Identity\EndpointIdentity;
use BAGArt\ProxyOperations\Domain\Identity\ProxyProtocol;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Cache;

function cachePolicyConfig(): array
{
    return config('proxy-operations.audit.cache');
}

function probeCacheForPolicy(CachePolicy $policy): LaravelCacheProbeCache
{
    return new LaravelCacheProbeCache($policy);
}

function policyCacheKey(): ProbeCacheKeyV3
{
    return new ProbeCacheKeyV3(
        endpointIdentity: new EndpointIdentity('1.2.3.4', 1080, ProxyProtocol::Socks5),
        credentialFingerprint: new CredentialFingerprint(str_repeat('a', 64)),
        checkerNodeId: 'node-1',
        egressIdentity: 'egress-eu-1',
        judgeSetVersion: 4,
        telegramDcSetVersion: 2,
        probeProfileVersion: 7,
        probeSemanticsVersion: 'probe-sem-2026.1',
        toolSemanticsVersion: 'curl-8.9-proto2',
    );
}

it('maps configured TTLs per kind', function (): void {
    $policy = CachePolicy::fromConfig(cachePolicyConfig());

    expect($policy->enabled)->toBeTrue()
        ->and($policy->keyPrefix)->toBe('proxy:probe-cache:')
        ->and($policy->defaultTtlSeconds)->toBe(1800)
        ->and($policy->negativeTtlSeconds)->toBe(120)
        ->and($policy->ttlForKind(SharedCacheValueKind::HttpMeasurement))->toBe(1800)
        ->and($policy->ttlForKind(SharedCacheValueKind::TimingMeasurement))->toBe(900)
        ->and($policy->ttlForKind(SharedCacheValueKind::ExitIpObservation))->toBe(3600)
        ->and($policy->ttlForKind(SharedCacheValueKind::NegativeProbeResult))->toBe(120);
});

it('falls back to the default TTL for kinds without an explicit entry', function (): void {
    $policy = CachePolicy::fromConfig(cachePolicyConfig());

    // Remove one mapping to simulate a config that lags behind the enum.
    $partial = CachePolicy::fromConfig([
        'enabled' => true,
        'default_ttl_seconds' => 777,
        'ttl_by_kind' => ['marker_result' => 60],
    ]);

    expect($partial->ttlForKind(SharedCacheValueKind::MarkerResult))->toBe(60)
        ->and($partial->ttlForKind(SharedCacheValueKind::DnsObservation))->toBe(777)
        ->and($policy->ttlForKind(SharedCacheValueKind::HttpMeasurement))->toBe(1800);
});

it('short-circuits without touching the store when disabled', function (): void {
    $repository = Mockery::mock(Repository::class);
    $repository->shouldReceive('get')->never();
    $repository->shouldReceive('put')->never();
    Cache::swap($repository);

    $policy = new CachePolicy(
        enabled: false,
        keyPrefix: 'proxy:probe-cache:',
        negativeTtlSeconds: 120,
        ttlByKind: [],
        defaultTtlSeconds: 1800,
    );
    $cache = probeCacheForPolicy($policy);

    $key = policyCacheKey();

    expect($cache->get($key, SharedCacheValueKind::HttpMeasurement))->toBeNull()
        ->and($cache->getNegative($key))->toBeNull();

    $cache->put($key, new SharedCacheValue(SharedCacheValueKind::HttpMeasurement, ['status' => 200]));
    $cache->putNegative($key, 'CONNECT_TIMEOUT');
});
