<?php

declare(strict_types=1);

use BAGArt\ProxyOperations\Domain\Cache\ProbeCacheKeyV3;
use BAGArt\ProxyOperations\Domain\Identity\CredentialFingerprint;
use BAGArt\ProxyOperations\Domain\Identity\EndpointIdentity;
use BAGArt\ProxyOperations\Domain\Identity\ProxyProtocol;

function cacheKeyEndpoint(): EndpointIdentity
{
    return new EndpointIdentity('1.2.3.4', 1080, ProxyProtocol::Socks5);
}

function makeCacheKey(): ProbeCacheKeyV3
{
    return new ProbeCacheKeyV3(
        endpointIdentity: cacheKeyEndpoint(),
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

it('produces the same hash regardless of construction order', function (): void {
    $first = new ProbeCacheKeyV3(
        endpointIdentity: cacheKeyEndpoint(),
        credentialFingerprint: new CredentialFingerprint(str_repeat('a', 64)),
        checkerNodeId: 'node-1',
        egressIdentity: 'egress-eu-1',
        judgeSetVersion: 4,
        telegramDcSetVersion: 2,
        probeProfileVersion: 7,
        probeSemanticsVersion: 'probe-sem-2026.1',
        toolSemanticsVersion: 'curl-8.9-proto2',
    );

    $second = new ProbeCacheKeyV3(
        toolSemanticsVersion: 'curl-8.9-proto2',
        probeSemanticsVersion: 'probe-sem-2026.1',
        probeProfileVersion: 7,
        telegramDcSetVersion: 2,
        judgeSetVersion: 4,
        egressIdentity: 'egress-eu-1',
        checkerNodeId: 'node-1',
        credentialFingerprint: new CredentialFingerprint(str_repeat('a', 64)),
        endpointIdentity: cacheKeyEndpoint(),
    );

    expect($second->toString())->toBe($first->toString())
        ->and($second->toHash())->toBe($first->toHash())
        ->and($first->toHash())->toMatch('/^[0-9a-f]{64}$/');
});

it('changes the hash when any field changes, including toolSemanticsVersion', function (): void {
    $baseHash = makeCacheKey()->toHash();

    $variants = [
        'endpoint' => new ProbeCacheKeyV3(new EndpointIdentity('1.2.3.5', 1080, ProxyProtocol::Socks5), new CredentialFingerprint(str_repeat('a', 64)), 'node-1', 'egress-eu-1', 4, 2, 7, 'probe-sem-2026.1', 'curl-8.9-proto2'),
        'credential' => new ProbeCacheKeyV3(cacheKeyEndpoint(), new CredentialFingerprint(str_repeat('b', 64)), 'node-1', 'egress-eu-1', 4, 2, 7, 'probe-sem-2026.1', 'curl-8.9-proto2'),
        'checkerNodeId' => new ProbeCacheKeyV3(cacheKeyEndpoint(), new CredentialFingerprint(str_repeat('a', 64)), 'node-2', 'egress-eu-1', 4, 2, 7, 'probe-sem-2026.1', 'curl-8.9-proto2'),
        'egressIdentity' => new ProbeCacheKeyV3(cacheKeyEndpoint(), new CredentialFingerprint(str_repeat('a', 64)), 'node-1', 'egress-us-1', 4, 2, 7, 'probe-sem-2026.1', 'curl-8.9-proto2'),
        'judgeSetVersion' => new ProbeCacheKeyV3(cacheKeyEndpoint(), new CredentialFingerprint(str_repeat('a', 64)), 'node-1', 'egress-eu-1', 5, 2, 7, 'probe-sem-2026.1', 'curl-8.9-proto2'),
        'telegramDcSetVersion' => new ProbeCacheKeyV3(cacheKeyEndpoint(), new CredentialFingerprint(str_repeat('a', 64)), 'node-1', 'egress-eu-1', 4, 3, 7, 'probe-sem-2026.1', 'curl-8.9-proto2'),
        'probeProfileVersion' => new ProbeCacheKeyV3(cacheKeyEndpoint(), new CredentialFingerprint(str_repeat('a', 64)), 'node-1', 'egress-eu-1', 4, 2, 8, 'probe-sem-2026.1', 'curl-8.9-proto2'),
        'probeSemanticsVersion' => new ProbeCacheKeyV3(cacheKeyEndpoint(), new CredentialFingerprint(str_repeat('a', 64)), 'node-1', 'egress-eu-1', 4, 2, 7, 'probe-sem-2026.2', 'curl-8.9-proto2'),
        'toolSemanticsVersion' => new ProbeCacheKeyV3(cacheKeyEndpoint(), new CredentialFingerprint(str_repeat('a', 64)), 'node-1', 'egress-eu-1', 4, 2, 7, 'probe-sem-2026.1', 'curl-8.10-proto2'),
    ];

    foreach ($variants as $field => $variant) {
        expect($variant->toHash())->not->toBe($baseHash, "hash must change when {$field} changes");
    }
});

it('is not fragmented by values containing field separators', function (): void {
    $sneaky = new ProbeCacheKeyV3(
        cacheKeyEndpoint(),
        new CredentialFingerprint(str_repeat('a', 64)),
        checkerNodeId: "node-1\x00\x00\x00\x16egress-us-1",
        egressIdentity: 'egress-eu-1',
        judgeSetVersion: 4,
        telegramDcSetVersion: 2,
        probeProfileVersion: 7,
        probeSemanticsVersion: 'probe-sem-2026.1',
        toolSemanticsVersion: 'curl-8.9-proto2',
    );

    $plain = new ProbeCacheKeyV3(
        cacheKeyEndpoint(),
        new CredentialFingerprint(str_repeat('a', 64)),
        checkerNodeId: 'node-1',
        egressIdentity: 'egress-eu-1',
        judgeSetVersion: 4,
        telegramDcSetVersion: 2,
        probeProfileVersion: 7,
        probeSemanticsVersion: 'probe-sem-2026.1',
        toolSemanticsVersion: 'curl-8.9-proto2',
    );

    expect($sneaky->toHash())->not->toBe($plain->toHash());
});

it('round-trips through JSON without changing the hash', function (): void {
    $key = makeCacheKey();

    $restored = ProbeCacheKeyV3::fromJson(json_decode(json_encode($key), true));

    expect($restored)->toEqual($key)
        ->and($restored->toHash())->toBe($key->toHash());
});

it('rejects unknown schema versions', function (): void {
    $payload = json_decode(json_encode(makeCacheKey()), true);
    $payload['schemaVersion'] = 99;

    ProbeCacheKeyV3::fromJson($payload);
})->throws(RuntimeException::class, 'Unsupported ProbeCacheKeyV3 schemaVersion');
