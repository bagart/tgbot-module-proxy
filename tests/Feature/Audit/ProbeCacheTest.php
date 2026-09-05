<?php

declare(strict_types=1);

use BAGArt\ProxyOperations\Audit\CachePolicy;
use BAGArt\ProxyOperations\Audit\LaravelCacheProbeCache;
use BAGArt\ProxyOperations\Audit\ProbeCacheEntry;
use BAGArt\ProxyOperations\Domain\Cache\ProbeCacheKeyV3;
use BAGArt\ProxyOperations\Domain\Cache\SharedCacheValue;
use BAGArt\ProxyOperations\Domain\Cache\SharedCacheValueKind;
use BAGArt\ProxyOperations\Domain\Identity\CredentialFingerprint;
use BAGArt\ProxyOperations\Domain\Identity\EndpointIdentity;
use BAGArt\ProxyOperations\Domain\Identity\ProxyProtocol;
use Illuminate\Cache\Repository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

function probeCache(): LaravelCacheProbeCache
{
    return new LaravelCacheProbeCache(CachePolicy::fromConfig(config('proxy-operations.audit.cache')));
}

function probeCacheKey(): ProbeCacheKeyV3
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

function probeCacheRawKey(ProbeCacheKeyV3 $key, SharedCacheValueKind $kind): string
{
    return 'proxy:probe-cache:'.$key->toHash().':'.$kind->value;
}

function probeCacheMeasurement(): SharedCacheValue
{
    return new SharedCacheValue(SharedCacheValueKind::HttpMeasurement, [
        'status' => 200,
        'total_time_ms' => 120,
    ]);
}

it('round-trips an entry and resolves the age at read time', function (): void {
    $cache = probeCache();
    $key = probeCacheKey();

    $cache->put($key, probeCacheMeasurement());

    $entry = $cache->get($key, SharedCacheValueKind::HttpMeasurement);

    expect($entry)->toBeInstanceOf(ProbeCacheEntry::class)
        ->and($entry->value)->toEqual(probeCacheMeasurement())
        ->and($entry->ageSeconds)->toBe(0);

    Carbon::setTestNow(Carbon::now()->addSeconds(30));

    $later = $cache->get($key, SharedCacheValueKind::HttpMeasurement);

    expect($later->ageSeconds)->toBe(30);
});

it('misses when any ProbeCacheKeyV3 field differs, including toolSemanticsVersion', function (string $field): void {
    probeCache()->put(probeCacheKey(), probeCacheMeasurement());

    $altered = new ProbeCacheKeyV3(
        endpointIdentity: $field === 'endpoint'
            ? new EndpointIdentity('5.6.7.8', 1080, ProxyProtocol::Socks5)
            : new EndpointIdentity('1.2.3.4', 1080, ProxyProtocol::Socks5),
        credentialFingerprint: new CredentialFingerprint(
            $field === 'credentialFingerprint' ? str_repeat('b', 64) : str_repeat('a', 64)
        ),
        checkerNodeId: $field === 'checkerNodeId' ? 'node-2' : 'node-1',
        egressIdentity: $field === 'egressIdentity' ? 'egress-us-1' : 'egress-eu-1',
        judgeSetVersion: $field === 'judgeSetVersion' ? 5 : 4,
        telegramDcSetVersion: $field === 'telegramDcSetVersion' ? 3 : 2,
        probeProfileVersion: $field === 'probeProfileVersion' ? 8 : 7,
        probeSemanticsVersion: $field === 'probeSemanticsVersion' ? 'probe-sem-2026.2' : 'probe-sem-2026.1',
        toolSemanticsVersion: $field === 'toolSemanticsVersion' ? 'curl-8.10-proto2' : 'curl-8.9-proto2',
    );

    expect(probeCache()->get($altered, SharedCacheValueKind::HttpMeasurement))->toBeNull();
})->with([
    'endpoint' => ['endpoint'],
    'credentialFingerprint' => ['credentialFingerprint'],
    'checkerNodeId' => ['checkerNodeId'],
    'egressIdentity' => ['egressIdentity'],
    'judgeSetVersion' => ['judgeSetVersion'],
    'telegramDcSetVersion' => ['telegramDcSetVersion'],
    'probeProfileVersion' => ['probeProfileVersion'],
    'probeSemanticsVersion' => ['probeSemanticsVersion'],
    'toolSemanticsVersion' => ['toolSemanticsVersion'],
]);

it('misses for a different kind under the same key', function (): void {
    $cache = probeCache();
    $key = probeCacheKey();

    $cache->put($key, probeCacheMeasurement());

    expect($cache->get($key, SharedCacheValueKind::TimingMeasurement))->toBeNull()
        ->and($cache->get($key, SharedCacheValueKind::HttpMeasurement))->not->toBeNull();
});

it('stores and reads back negative markers', function (): void {
    $cache = probeCache();
    $key = probeCacheKey();

    $cache->putNegative($key, 'CONNECT_TIMEOUT');

    expect($cache->getNegative($key))->toBe('CONNECT_TIMEOUT')
        ->and($cache->get($key, SharedCacheValueKind::NegativeProbeResult)->value->payload['checked_at_ms'])->toBeInt();
});

it('expires negative markers after the negative TTL', function (): void {
    $cache = probeCache();
    $key = probeCacheKey();

    $cache->putNegative($key, 'CONNECT_TIMEOUT');
    expect($cache->getNegative($key))->toBe('CONNECT_TIMEOUT');

    Carbon::setTestNow(Carbon::now()->addSeconds(121));

    expect($cache->getNegative($key))->toBeNull();
});

it('treats a corrupt payload as a miss without throwing', function (): void {
    $cache = probeCache();
    $key = probeCacheKey();

    Cache::put(probeCacheRawKey($key, SharedCacheValueKind::HttpMeasurement), 'this-is-not-json', 600);
    expect($cache->get($key, SharedCacheValueKind::HttpMeasurement))->toBeNull();

    Cache::put(probeCacheRawKey($key, SharedCacheValueKind::HttpMeasurement), '{"schemaVersion":1}', 600);
    expect($cache->get($key, SharedCacheValueKind::HttpMeasurement))->toBeNull();

    Cache::put(probeCacheRawKey($key, SharedCacheValueKind::HttpMeasurement), '{"schemaVersion":99,"value":{}}', 600);
    expect($cache->get($key, SharedCacheValueKind::HttpMeasurement))->toBeNull();
});

it('survives a failing store: get returns null and put does not throw', function (): void {
    $repository = Mockery::mock(Repository::class);
    $repository->shouldReceive('get')->andThrow(new RuntimeException('store down'));
    $repository->shouldReceive('put')->andThrow(new RuntimeException('store down'));
    Cache::swap($repository);

    $cache = probeCache();
    $key = probeCacheKey();

    expect($cache->get($key, SharedCacheValueKind::HttpMeasurement))->toBeNull()
        ->and($cache->getNegative($key))->toBeNull();

    $cache->put($key, probeCacheMeasurement());
    $cache->putNegative($key, 'CONNECT_TIMEOUT');

    expect($cache->getNegative($key))->toBeNull();
});
