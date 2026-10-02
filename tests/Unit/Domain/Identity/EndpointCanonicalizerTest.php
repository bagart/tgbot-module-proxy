<?php

declare(strict_types=1);

use BAGArt\ProxyOperations\Domain\Identity\EndpointCanonicalizer;
use BAGArt\ProxyOperations\Domain\Identity\ProxyProtocol;

beforeEach(fn (): object => $this->canonicalizer = new EndpointCanonicalizer());

it('canonicalizes unicode IDN hosts to punycode', function (string $input, string $expected): void {
    expect($this->canonicalizer->canonicalize($input, 8080, ProxyProtocol::Http)->host)->toBe($expected);
})->with([
    ['ПРИМЕР.РФ', 'xn--e1afmkfd.xn--p1ai'],
    ['Bücher.example', 'xn--bcher-kva.example'],
    ['XN--E1AFMKFD.XN--P1AI', 'xn--e1afmkfd.xn--p1ai'],
]);

it('lowercases hosts and strips trailing dots', function (): void {
    $identity = $this->canonicalizer->canonicalize('Example.COM.', 8080, ProxyProtocol::Http);

    expect($identity->host)->toBe('example.com')
        ->and($identity->toString())->toBe('http://example.com:8080');
});

it('strips default ports per protocol', function (string $host, int $port, ProxyProtocol $protocol, string $expected): void {
    expect($this->canonicalizer->canonicalize($host, $port, $protocol)->toString())->toBe($expected);
})->with([
    ['example.com', 80, ProxyProtocol::Http, 'http://example.com'],
    ['example.com', 443, ProxyProtocol::Https, 'https://example.com'],
    ['example.com', 1080, ProxyProtocol::Socks5, 'socks5://example.com'],
    ['example.com', 1080, ProxyProtocol::Socks5h, 'socks5h://example.com'],
    ['example.com', 1080, ProxyProtocol::Socks4a, 'socks4a://example.com'],
    ['::1', 443, ProxyProtocol::Mtproto, 'mtproto://[::1]'],
]);

it('preserves non-default ports', function (): void {
    expect($this->canonicalizer->canonicalize('example.com', 9090, ProxyProtocol::Socks5)->toString())
        ->toBe('socks5://example.com:9090');
});

it('fills in the default port when omitted from identity data', function (): void {
    $explicit = $this->canonicalizer->canonicalize('example.com', 1080, ProxyProtocol::Socks5);

    expect($explicit->port)->toBe(1080)
        ->and($this->canonicalizer->canonicalize('example.com', 8080, ProxyProtocol::Http)->port)->toBe(8080);
});

it('normalizes IPv6 addresses to RFC 5952 lowercase compressed form', function (string $input, string $expected): void {
    expect($this->canonicalizer->canonicalize($input, 1081, ProxyProtocol::Socks5)->host)->toBe($expected);
})->with([
    ['::1', '::1'],
    ['0:0:0:0:0:0:0:1', '::1'],
    ['2001:0DB8:0000:0000:0000:0000:0000:0001', '2001:db8::1'],
    ['[2001:db8::1]', '2001:db8::1'],
    ['2001:DB8:0:0:1:0:0:1', '2001:db8::1:0:0:1'],
    ['2001:db8::192.168.0.1', '2001:db8::c0a8:1'],
]);

it('produces identical identities for equivalent spellings', function (): void {
    $a = $this->canonicalizer->canonicalize('EXAMPLE.com.', 80, ProxyProtocol::Http);
    $b = $this->canonicalizer->canonicalize('example.com', 80, ProxyProtocol::Http);

    expect($a->equals($b))->toBeTrue();
});

it('rejects invalid hosts and ports', function (array $args): mixed {
    return $this->canonicalizer->canonicalize(...$args);
})
    ->with([
        [['', 8080, ProxyProtocol::Http]],
        [['   ', 8080, ProxyProtocol::Http]],
        [['not a host!', 8080, ProxyProtocol::Http]],
        [['zz:::gg', 8080, ProxyProtocol::Socks5]],
        [['example.com', 0, ProxyProtocol::Http]],
        [['example.com', 65536, ProxyProtocol::Http]],
        [['example.com', -1, ProxyProtocol::Http]],
    ])->throws(InvalidArgumentException::class);
