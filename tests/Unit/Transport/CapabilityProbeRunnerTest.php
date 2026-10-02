<?php

declare(strict_types=1);

use BAGArt\ProxyOperations\Domain\Identity\ProxyProtocol;
use BAGArt\ProxyOperations\Transport\Adapters\TransportAdapterContract;
use BAGArt\ProxyOperations\Transport\Adapters\TransportConnectionException;
use BAGArt\ProxyOperations\Transport\Adapters\UdpAssociateProbeContract;
use BAGArt\ProxyOperations\Transport\CapabilityProbeRunner;
use BAGArt\ProxyOperations\Transport\DnsResolverFactory;
use BAGArt\ProxyOperations\Transport\HttpConnectOptions;
use BAGArt\ProxyOperations\Transport\ProxyConfig;
use BAGArt\ProxyOperations\Transport\SocksOptions;
use BAGArt\ProxyOperations\Transport\TlsOptions;
use BAGArt\ProxyOperations\Transport\TransportAdapterResolver;

function httpProxyConfig(): ProxyConfig
{
    return new ProxyConfig(
        scheme: ProxyProtocol::Http,
        host: '127.0.0.1',
        port: 8080,
        credential: null,
        tls: new TlsOptions(),
        transportOptions: new HttpConnectOptions(),
    );
}

function socks5ProxyConfig(): ProxyConfig
{
    return new ProxyConfig(
        scheme: ProxyProtocol::Socks5,
        host: '127.0.0.1',
        port: 1080,
        credential: null,
        tls: new TlsOptions(),
        transportOptions: new SocksOptions(enableUdpAssociate: true),
    );
}

function buildRunner(
    ?TransportAdapterResolver $resolver = null,
    ?DnsResolverFactory $dnsFactory = null,
    ?UdpAssociateProbeContract $udpAdapter = null,
): CapabilityProbeRunner {
    $adapterResolver = $resolver ?? new TransportAdapterResolver();
    $dnsResolverFactory = $dnsFactory ?? new DnsResolverFactory();
    $udp = $udpAdapter ?? Mockery::mock(UdpAssociateProbeContract::class);

    return new CapabilityProbeRunner($adapterResolver, $dnsResolverFactory, $udp);
}

it('probeTcpConnectivity returns unreachable when adapter throws', function (): void {
    $adapter = Mockery::mock(TransportAdapterContract::class);
    $adapter->shouldReceive('connect')->once()->andThrow(
        new TransportConnectionException('Connection refused'),
    );

    $resolver = new TransportAdapterResolver();
    $resolver->register(ProxyProtocol::Http, $adapter);

    $runner = buildRunner(resolver: $resolver);
    $result = $runner->probeTcpConnectivity(httpProxyConfig());

    expect($result->tcpReachable)->toBeFalse();
});

it('probeTcpConnectivity returns reachable on success', function (): void {
    $stream = fopen('php://memory', 'r+');
    $adapter = Mockery::mock(TransportAdapterContract::class);
    $adapter->shouldReceive('connect')->once()->andReturn($stream);
    $adapter->shouldReceive('close')->once();

    $resolver = new TransportAdapterResolver();
    $resolver->register(ProxyProtocol::Http, $adapter);

    $runner = buildRunner(resolver: $resolver);
    $result = $runner->probeTcpConnectivity(httpProxyConfig());

    expect($result->tcpReachable)->toBeTrue();
});

it('probeUdpSupport returns false for non-SOCKS5 protocol', function (): void {
    $runner = buildRunner();
    $result = $runner->probeUdpSupport(httpProxyConfig());

    expect($result->udpSupported)->toBeFalse();
});

it('probeAll aggregates capabilities', function (): void {
    $adapter = Mockery::mock(TransportAdapterContract::class);
    $adapter->shouldReceive('connect')->once()->andReturn(fopen('php://memory', 'r+'));
    $adapter->shouldReceive('close')->once();

    $resolver = new TransportAdapterResolver();
    $resolver->register(ProxyProtocol::Http, $adapter);

    $runner = buildRunner(resolver: $resolver);
    $result = $runner->probeAll(httpProxyConfig());

    expect($result->tcpReachable)->toBeTrue()
        ->and($result->capabilities)->toHaveKey('tcp');
});

it('probeAll returns unreachable when TCP fails', function (): void {
    $adapter = Mockery::mock(TransportAdapterContract::class);
    $adapter->shouldReceive('connect')->once()->andThrow(
        new TransportConnectionException('fail'),
    );

    $resolver = new TransportAdapterResolver();
    $resolver->register(ProxyProtocol::Http, $adapter);

    $runner = buildRunner(resolver: $resolver);
    $result = $runner->probeAll(httpProxyConfig());

    expect($result->tcpReachable)->toBeFalse();
});
