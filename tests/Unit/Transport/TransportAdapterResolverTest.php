<?php

declare(strict_types=1);

use BAGArt\ProxyOperations\Domain\Identity\ProxyProtocol;
use BAGArt\ProxyOperations\Transport\Adapters\TransportAdapterContract;
use BAGArt\ProxyOperations\Transport\Adapters\TransportConnectionException;
use BAGArt\ProxyOperations\Transport\TransportAdapterResolver;

beforeEach(function (): void {
    $this->resolver = new TransportAdapterResolver();
});

it('resolves a registered protocol to the correct adapter', function (): void {
    $adapter = Mockery::mock(TransportAdapterContract::class);
    $this->resolver->register(ProxyProtocol::Socks5, $adapter);

    $result = $this->resolver->resolve(ProxyProtocol::Socks5);

    expect($result)->toBe($adapter);
});

it('throws TransportConnectionException for unregistered protocol', function (): void {
    $this->resolver->resolve(ProxyProtocol::Socks5);
})->throws(TransportConnectionException::class, 'No transport adapter registered');

it('returns list of supported protocols', function (): void {
    $adapter5 = Mockery::mock(TransportAdapterContract::class);
    $adapterHttp = Mockery::mock(TransportAdapterContract::class);
    $this->resolver->register(ProxyProtocol::Socks5, $adapter5);
    $this->resolver->register(ProxyProtocol::Http, $adapterHttp);

    $supported = $this->resolver->supported();

    expect($supported)->toHaveCount(2);
    expect($supported)->toContain(ProxyProtocol::Socks5);
    expect($supported)->toContain(ProxyProtocol::Http);
});

it('returns empty list when no adapters registered', function (): void {
    $supported = $this->resolver->supported();

    expect($supported)->toBeEmpty();
});

it('overwrites adapter for same protocol', function (): void {
    $adapter1 = Mockery::mock(TransportAdapterContract::class);
    $adapter2 = Mockery::mock(TransportAdapterContract::class);

    $this->resolver->register(ProxyProtocol::Socks5, $adapter1);
    $this->resolver->register(ProxyProtocol::Socks5, $adapter2);

    $result = $this->resolver->resolve(ProxyProtocol::Socks5);

    expect($result)->toBe($adapter2);
});

it('resolves multiple protocols independently', function (): void {
    $adapter4 = Mockery::mock(TransportAdapterContract::class);
    $adapter5 = Mockery::mock(TransportAdapterContract::class);
    $adapterHttp = Mockery::mock(TransportAdapterContract::class);

    $this->resolver->register(ProxyProtocol::Socks4, $adapter4);
    $this->resolver->register(ProxyProtocol::Socks5, $adapter5);
    $this->resolver->register(ProxyProtocol::Http, $adapterHttp);

    expect($this->resolver->resolve(ProxyProtocol::Socks4))->toBe($adapter4);
    expect($this->resolver->resolve(ProxyProtocol::Socks5))->toBe($adapter5);
    expect($this->resolver->resolve(ProxyProtocol::Http))->toBe($adapterHttp);
});
