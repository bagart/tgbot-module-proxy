<?php

declare(strict_types=1);

use BAGArt\ProxyOperations\Domain\Probe\ProbeType;
use BAGArt\ProxyOperations\Tool\ToolManifest;
use BAGArt\ProxyOperations\Transport\TransportToolManifestProvider;

it('returns eight manifests', function (): void {
    $provider = new TransportToolManifestProvider();
    $manifests = $provider->manifests();

    expect($manifests)->toHaveCount(8);
});

it('all manifests have non-empty name, version, and capabilities', function (): void {
    $manifests = (new TransportToolManifestProvider())->manifests();

    foreach ($manifests as $manifest) {
        expect($manifest->name->value)->not->toBeEmpty()
            ->and($manifest->version)->not->toBeEmpty()
            ->and($manifest->capabilities->probeTypes)->not->toBeEmpty()
            ->and($manifest->capabilities->protocols)->not->toBeEmpty();
    }
});

it('HTTP manifest supports HttpLiveness probe type', function (): void {
    $manifests = (new TransportToolManifestProvider())->manifests();
    $httpManifest = collect($manifests)->first(fn ($m) => $m->name->value === 'http-connect-adapter');

    expect($httpManifest)->not->toBeNull()
        ->and($httpManifest->capabilities->probeTypes)->toContain(ProbeType::HttpLiveness);
});

it('SOCKS5 manifest supports UdpAssociate and DnsResolution probe types', function (): void {
    $manifests = (new TransportToolManifestProvider())->manifests();
    $socks5Manifest = collect($manifests)->first(fn ($m) => $m->name->value === 'socks5-adapter');

    expect($socks5Manifest)->not->toBeNull()
        ->and($socks5Manifest->capabilities->probeTypes)->toContain(ProbeType::UdpAssociate)
        ->and($socks5Manifest->capabilities->probeTypes)->toContain(ProbeType::DnsResolution);
});

it('includes MTProto in transport manifests', function (): void {
    $manifests = (new TransportToolManifestProvider())->manifests();
    $mtproto = collect($manifests)->first(fn ($m) => str_contains($m->name->value, 'mtproto'));

    expect($mtproto)->not->toBeNull()
        ->and($mtproto->capabilities->probeTypes)->toContain(ProbeType::MtprotoHandshake);
});

it('manifests are JsonSerializable and round-trip through fromJson', function (): void {
    $manifests = (new TransportToolManifestProvider())->manifests();

    foreach ($manifests as $manifest) {
        $json = $manifest->jsonSerialize();
        expect($json)->toHaveKey('name')
            ->and($json)->toHaveKey('version')
            ->and($json)->toHaveKey('capabilities');

        $roundTripped = ToolManifest::fromJson($json);
        expect($roundTripped->name->value)->toBe($manifest->name->value);
    }
});
