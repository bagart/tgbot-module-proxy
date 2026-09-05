<?php

declare(strict_types=1);

use BAGArt\ProxyOperations\Domain\Identity\EndpointIdentity;
use BAGArt\ProxyOperations\Domain\Identity\ProxyProtocol;

it('renders canonical URI with bracketed IPv6 and omitted default port', function (): void {
    expect(new EndpointIdentity('example.com', 80, ProxyProtocol::Http)->toString())->toBe('http://example.com')
        ->and(new EndpointIdentity('example.com', 8080, ProxyProtocol::Http)->toString())->toBe('http://example.com:8080')
        ->and(new EndpointIdentity('2001:db8::1', 1081, ProxyProtocol::Socks5)->toString())->toBe('socks5://[2001:db8::1]:1081');
});

it('compares by full field equality', function (): void {
    $base = new EndpointIdentity('example.com', 8080, ProxyProtocol::Http);

    expect($base->equals(new EndpointIdentity('example.com', 8080, ProxyProtocol::Http)))->toBeTrue()
        ->and($base->equals(new EndpointIdentity('example.org', 8080, ProxyProtocol::Http)))->toBeFalse()
        ->and($base->equals(new EndpointIdentity('example.com', 8081, ProxyProtocol::Http)))->toBeFalse()
        ->and($base->equals(new EndpointIdentity('example.com', 8080, ProxyProtocol::Https)))->toBeFalse();
});

it('round-trips through JSON', function (): void {
    $identity = new EndpointIdentity('2001:db8::1', 1081, ProxyProtocol::Socks5h);
    $restored = EndpointIdentity::fromJson(json_decode(json_encode($identity), true));

    expect($restored)->toEqual($identity)
        ->and($restored->equals($identity))->toBeTrue()
        ->and($restored->toString())->toBe($identity->toString());
});

it('rejects unknown schema versions', function (): void {
    EndpointIdentity::fromJson(['host' => 'example.com', 'port' => 80, 'protocol' => 'http', 'schemaVersion' => 99]);
})->throws(RuntimeException::class, 'Unsupported EndpointIdentity schemaVersion');
