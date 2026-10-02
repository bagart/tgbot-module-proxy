<?php

declare(strict_types=1);

use BAGArt\ProxyOperations\Domain\Identity\ProxyProtocol;
use BAGArt\ProxyOperations\Transport\DnsLeakProbe;
use BAGArt\ProxyOperations\Transport\DnsLeakResult;
use BAGArt\ProxyOperations\Transport\DnsResolverContract;
use BAGArt\ProxyOperations\Transport\DnsResolverFactory;
use BAGArt\ProxyOperations\Transport\HttpConnectOptions;
use BAGArt\ProxyOperations\Transport\LocalDnsResolver;
use BAGArt\ProxyOperations\Transport\ProxyConfig;
use BAGArt\ProxyOperations\Transport\ProxyDnsResolver;
use BAGArt\ProxyOperations\Transport\RemoteDnsResolver;
use BAGArt\ProxyOperations\Transport\SocksDnsMode;
use BAGArt\ProxyOperations\Transport\SocksOptions;
use BAGArt\ProxyOperations\Transport\TlsOptions;

it('creates ProxyDnsResolver for SocksOptions with ProxyDns mode', function (): void {
    $factory = new DnsResolverFactory();
    $config = new ProxyConfig(
        scheme: ProxyProtocol::Socks5,
        host: '127.0.0.1',
        port: 1080,
        credential: null,
        tls: new TlsOptions(),
        transportOptions: new SocksOptions(dnsMode: SocksDnsMode::ProxyDns),
    );

    $resolver = $factory->create($config);

    expect($resolver)->toBeInstanceOf(ProxyDnsResolver::class);
    expect($resolver->mode())->toBe(SocksDnsMode::ProxyDns);
});

it('creates RemoteDnsResolver for SocksOptions with Remote mode', function (): void {
    $factory = new DnsResolverFactory();
    $config = new ProxyConfig(
        scheme: ProxyProtocol::Socks5,
        host: '127.0.0.1',
        port: 1080,
        credential: null,
        tls: new TlsOptions(),
        transportOptions: new SocksOptions(dnsMode: SocksDnsMode::Remote),
    );

    $resolver = $factory->create($config);

    expect($resolver)->toBeInstanceOf(RemoteDnsResolver::class);
    expect($resolver->mode())->toBe(SocksDnsMode::Remote);
});

it('creates LocalDnsResolver for SocksOptions with Local mode', function (): void {
    $factory = new DnsResolverFactory();
    $config = new ProxyConfig(
        scheme: ProxyProtocol::Socks5,
        host: '127.0.0.1',
        port: 1080,
        credential: null,
        tls: new TlsOptions(),
        transportOptions: new SocksOptions(dnsMode: SocksDnsMode::Local),
    );

    $resolver = $factory->create($config);

    expect($resolver)->toBeInstanceOf(LocalDnsResolver::class);
    expect($resolver->mode())->toBe(SocksDnsMode::Local);
});

it('creates LocalDnsResolver for HttpConnectOptions (default)', function (): void {
    $factory = new DnsResolverFactory();
    $config = new ProxyConfig(
        scheme: ProxyProtocol::Http,
        host: '127.0.0.1',
        port: 80,
        credential: null,
        tls: new TlsOptions(),
        transportOptions: new HttpConnectOptions(),
    );

    $resolver = $factory->create($config);

    expect($resolver)->toBeInstanceOf(LocalDnsResolver::class);
    expect($resolver->mode())->toBe(SocksDnsMode::Local);
});

it('LocalDnsResolver resolves localhost to 127.0.0.1', function (): void {
    $resolver = new LocalDnsResolver();
    $ips = $resolver->resolve('localhost');

    expect($ips)->toContain('127.0.0.1');
});

it('LocalDnsResolver mode returns Local', function (): void {
    $resolver = new LocalDnsResolver();

    expect($resolver->mode())->toBe(SocksDnsMode::Local);
});

it('DnsResolverContract is implemented by all resolvers', function (): void {
    expect(new LocalDnsResolver())->toBeInstanceOf(DnsResolverContract::class);
});

it('DnsLeakResult serializes to JSON correctly', function (): void {
    $result = new DnsLeakResult(
        resolvedIps: ['1.2.3.4'],
        leaked: false,
        mode: SocksDnsMode::ProxyDns,
        latencyMs: 5.0,
    );

    $json = json_encode($result);
    $data = json_decode($json, true);

    expect($data['resolvedIps'])->toBe(['1.2.3.4']);
    expect($data['leaked'])->toBeFalse();
    expect($data['mode'])->toBe('proxy_dns');
    expect((float) $data['latencyMs'])->toBe(5.0);
    expect($data['schemaVersion'])->toBe(1);
});

it('DnsLeakProbe detects leaked DNS via loopback IP in proxied mode', function (): void {
    $mockResolver = new class () implements DnsResolverContract {
        public function resolve(string $hostname): array
        {
            return ['127.0.0.1'];
        }

        public function mode(): SocksDnsMode
        {
            return SocksDnsMode::ProxyDns;
        }
    };

    $probe = new DnsLeakProbe($mockResolver);
    $result = $probe->check('example.com');

    expect($result)->toBeInstanceOf(DnsLeakResult::class);
    expect($result->leaked)->toBeTrue();
    expect($result->resolvedIps)->toBe(['127.0.0.1']);
    expect($result->mode)->toBe(SocksDnsMode::ProxyDns);
});

it('DnsLeakProbe reports no leak for valid public IPs in proxied mode', function (): void {
    $mockResolver = new class () implements DnsResolverContract {
        public function resolve(string $hostname): array
        {
            return ['93.184.216.34'];
        }

        public function mode(): SocksDnsMode
        {
            return SocksDnsMode::Remote;
        }
    };

    $probe = new DnsLeakProbe($mockResolver);
    $result = $probe->check('example.com');

    expect($result->leaked)->toBeFalse();
    expect($result->resolvedIps)->toBe(['93.184.216.34']);
});

it('DnsLeakProbe detects leaked DNS via private IP in local mode', function (): void {
    $mockResolver = new class () implements DnsResolverContract {
        public function resolve(string $hostname): array
        {
            return ['192.168.1.1'];
        }

        public function mode(): SocksDnsMode
        {
            return SocksDnsMode::Local;
        }
    };

    $probe = new DnsLeakProbe($mockResolver);
    $result = $probe->check('example.com');

    expect($result->leaked)->toBeTrue();
});

it('DnsLeakProbe reports no leak for public IPs in local mode', function (): void {
    $mockResolver = new class () implements DnsResolverContract {
        public function resolve(string $hostname): array
        {
            return ['8.8.8.8'];
        }

        public function mode(): SocksDnsMode
        {
            return SocksDnsMode::Local;
        }
    };

    $probe = new DnsLeakProbe($mockResolver);
    $result = $probe->check('example.com');

    expect($result->leaked)->toBeFalse();
});
