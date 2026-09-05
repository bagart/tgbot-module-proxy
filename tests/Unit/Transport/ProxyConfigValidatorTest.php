<?php

declare(strict_types=1);

use BAGArt\ProxyOperations\Domain\Identity\ProxyProtocol;
use BAGArt\ProxyOperations\Tool\FileDescriptorChannel;
use BAGArt\ProxyOperations\Tool\StdinChannel;
use BAGArt\ProxyOperations\Transport\HttpConnectOptions;
use BAGArt\ProxyOperations\Transport\MtprotoOptions;
use BAGArt\ProxyOperations\Transport\ProxyConfig;
use BAGArt\ProxyOperations\Transport\ProxyConfigValidator;
use BAGArt\ProxyOperations\Transport\ProxyCredentialRef;
use BAGArt\ProxyOperations\Transport\SocksDnsMode;
use BAGArt\ProxyOperations\Transport\SocksOptions;
use BAGArt\ProxyOperations\Transport\TlsOptions;

beforeEach(function (): void {
    $this->validator = new ProxyConfigValidator;
});

it('accepts valid SOCKS5 config with StdinChannel', function (): void {
    $config = new ProxyConfig(
        scheme: ProxyProtocol::Socks5,
        host: '10.0.0.1',
        port: 1080,
        credential: new ProxyCredentialRef(username: 'user', channel: new StdinChannel),
        tls: new TlsOptions,
        transportOptions: new SocksOptions,
    );

    $this->validator->validate($config);

    expect(true)->toBeTrue();
});

it('accepts valid HTTP CONNECT config with FileDescriptorChannel', function (): void {
    $config = new ProxyConfig(
        scheme: ProxyProtocol::Http,
        host: 'proxy.example.com',
        port: 8080,
        credential: new ProxyCredentialRef(username: null, channel: new FileDescriptorChannel(fileDescriptor: 3)),
        tls: new TlsOptions,
        transportOptions: new HttpConnectOptions,
    );

    $this->validator->validate($config);

    expect(true)->toBeTrue();
});

it('accepts valid MTProto config without credential', function (): void {
    $config = new ProxyConfig(
        scheme: ProxyProtocol::Mtproto,
        host: 'mtproto.example.com',
        port: 443,
        credential: null,
        tls: new TlsOptions,
        transportOptions: new MtprotoOptions,
    );

    $this->validator->validate($config);

    expect(true)->toBeTrue();
});

it('rejects ProxyDns on Socks5 (not Socks5h)', function (): void {
    $config = new ProxyConfig(
        scheme: ProxyProtocol::Socks5,
        host: '10.0.0.1',
        port: 1080,
        credential: null,
        tls: new TlsOptions,
        transportOptions: new SocksOptions(dnsMode: SocksDnsMode::ProxyDns),
    );

    $this->validator->validate($config);
})->throws(InvalidArgumentException::class, 'ProxyDns mode is only supported for Socks5h');

it('accepts ProxyDns on Socks5h', function (): void {
    $config = new ProxyConfig(
        scheme: ProxyProtocol::Socks5h,
        host: '10.0.0.1',
        port: 1080,
        credential: null,
        tls: new TlsOptions,
        transportOptions: new SocksOptions(dnsMode: SocksDnsMode::ProxyDns),
    );

    $this->validator->validate($config);

    expect(true)->toBeTrue();
});

it('rejects SocksOptions for Http scheme', function (): void {
    $config = new ProxyConfig(
        scheme: ProxyProtocol::Http,
        host: 'proxy.example.com',
        port: 80,
        credential: null,
        tls: new TlsOptions,
        transportOptions: new SocksOptions,
    );

    $this->validator->validate($config);
})->throws(InvalidArgumentException::class, 'HttpConnectOptions');

it('rejects HttpConnectOptions for Socks5 scheme', function (): void {
    $config = new ProxyConfig(
        scheme: ProxyProtocol::Socks5,
        host: '10.0.0.1',
        port: 1080,
        credential: null,
        tls: new TlsOptions,
        transportOptions: new HttpConnectOptions,
    );

    $this->validator->validate($config);
})->throws(InvalidArgumentException::class, 'SocksOptions');

it('rejects StdinChannel on Http protocol', function (): void {
    $config = new ProxyConfig(
        scheme: ProxyProtocol::Http,
        host: 'proxy.example.com',
        port: 80,
        credential: new ProxyCredentialRef(username: 'user', channel: new StdinChannel),
        tls: new TlsOptions,
        transportOptions: new HttpConnectOptions,
    );

    $this->validator->validate($config);
})->throws(InvalidArgumentException::class, 'StdinChannel is not supported for protocol');

it('accepts FileDescriptorChannel on Socks5', function (): void {
    $config = new ProxyConfig(
        scheme: ProxyProtocol::Socks5,
        host: '10.0.0.1',
        port: 1080,
        credential: new ProxyCredentialRef(username: null, channel: new FileDescriptorChannel(fileDescriptor: 3)),
        tls: new TlsOptions,
        transportOptions: new SocksOptions,
    );

    $this->validator->validate($config);

    expect(true)->toBeTrue();
});

it('accepts config without credential', function (): void {
    $config = new ProxyConfig(
        scheme: ProxyProtocol::Socks5,
        host: '10.0.0.1',
        port: 1080,
        credential: null,
        tls: new TlsOptions,
        transportOptions: new SocksOptions,
    );

    $this->validator->validate($config);

    expect(true)->toBeTrue();
});

it('rejects SocksOptions on Socks4 (UDP associate check)', function (): void {
    $config = new ProxyConfig(
        scheme: ProxyProtocol::Socks4,
        host: '10.0.0.1',
        port: 1080,
        credential: null,
        tls: new TlsOptions,
        transportOptions: new SocksOptions(enableUdpAssociate: true),
    );

    $this->validator->validate($config);
})->throws(InvalidArgumentException::class, 'HttpConnectOptions');

it('rejects SocksOptions on Http (type mismatch)', function (): void {
    $config = new ProxyConfig(
        scheme: ProxyProtocol::Http,
        host: 'proxy.example.com',
        port: 80,
        credential: null,
        tls: new TlsOptions,
        transportOptions: new SocksOptions(enableUdpAssociate: true),
    );

    $this->validator->validate($config);
})->throws(InvalidArgumentException::class, 'HttpConnectOptions');

it('accepts enableUdpAssociate on Socks5h', function (): void {
    $config = new ProxyConfig(
        scheme: ProxyProtocol::Socks5h,
        host: '10.0.0.1',
        port: 1080,
        credential: null,
        tls: new TlsOptions,
        transportOptions: new SocksOptions(enableUdpAssociate: true),
    );

    $this->validator->validate($config);

    expect(true)->toBeTrue();
});

it('rejects StdinChannel on Mtproto', function (): void {
    $config = new ProxyConfig(
        scheme: ProxyProtocol::Mtproto,
        host: 'mtproto.example.com',
        port: 443,
        credential: new ProxyCredentialRef(username: 'user', channel: new StdinChannel),
        tls: new TlsOptions,
        transportOptions: new MtprotoOptions,
    );

    $this->validator->validate($config);
})->throws(InvalidArgumentException::class, 'StdinChannel is not supported for protocol');

it('accepts enableUdpAssociate on Socks5', function (): void {
    $config = new ProxyConfig(
        scheme: ProxyProtocol::Socks5,
        host: '10.0.0.1',
        port: 1080,
        credential: null,
        tls: new TlsOptions,
        transportOptions: new SocksOptions(enableUdpAssociate: true),
    );

    $this->validator->validate($config);

    expect(true)->toBeTrue();
});
