<?php

declare(strict_types=1);

use BAGArt\ProxyOperations\Domain\Identity\ProxyProtocol;
use BAGArt\ProxyOperations\Tool\FileDescriptorChannel;
use BAGArt\ProxyOperations\Tool\StdinChannel;
use BAGArt\ProxyOperations\Transport\HttpConnectOptions;
use BAGArt\ProxyOperations\Transport\MtprotoOptions;
use BAGArt\ProxyOperations\Transport\ProxyConfig;
use BAGArt\ProxyOperations\Transport\ProxyCredentialRef;
use BAGArt\ProxyOperations\Transport\SocksDnsMode;
use BAGArt\ProxyOperations\Transport\SocksOptions;
use BAGArt\ProxyOperations\Transport\TlsOptions;

function makeSocks5Config(): ProxyConfig
{
    return new ProxyConfig(
        scheme: ProxyProtocol::Socks5,
        host: '10.0.0.1',
        port: 1080,
        credential: new ProxyCredentialRef(
            username: 'user1',
            channel: new StdinChannel,
        ),
        tls: new TlsOptions,
        transportOptions: new SocksOptions,
    );
}

function makeHttpConfig(): ProxyConfig
{
    return new ProxyConfig(
        scheme: ProxyProtocol::Http,
        host: 'proxy.example.com',
        port: 8080,
        credential: new ProxyCredentialRef(
            username: null,
            channel: new FileDescriptorChannel(fileDescriptor: 3),
        ),
        tls: new TlsOptions(verifyPeer: false, allowSelfSigned: true),
        transportOptions: new HttpConnectOptions,
    );
}

function makeMtprotoConfig(): ProxyConfig
{
    return new ProxyConfig(
        scheme: ProxyProtocol::Mtproto,
        host: 'mtproto.example.com',
        port: 443,
        credential: null,
        tls: new TlsOptions,
        transportOptions: new MtprotoOptions,
    );
}

it('rejects empty host', function (): void {
    new ProxyConfig(
        scheme: ProxyProtocol::Http,
        host: '',
        port: 80,
        credential: null,
        tls: new TlsOptions,
        transportOptions: new HttpConnectOptions,
    );
})->throws(InvalidArgumentException::class, 'host must not be empty');

it('rejects port out of range (low)', function (): void {
    new ProxyConfig(
        scheme: ProxyProtocol::Http,
        host: 'example.com',
        port: 0,
        credential: null,
        tls: new TlsOptions,
        transportOptions: new HttpConnectOptions,
    );
})->throws(InvalidArgumentException::class, 'port must be within 1..65535');

it('rejects port out of range (high)', function (): void {
    new ProxyConfig(
        scheme: ProxyProtocol::Http,
        host: 'example.com',
        port: 70000,
        credential: null,
        tls: new TlsOptions,
        transportOptions: new HttpConnectOptions,
    );
})->throws(InvalidArgumentException::class, 'port must be within 1..65535');

it('round-trips SOCKS5 config through JSON', function (): void {
    $config = makeSocks5Config();
    $json = $config->jsonSerialize();

    expect($json['schemaVersion'])->toBe(ProxyConfig::SCHEMA_VERSION);
    expect($json['scheme'])->toBe('socks5');
    expect($json['host'])->toBe('10.0.0.1');
    expect($json['port'])->toBe(1080);

    $restored = ProxyConfig::fromJson($json);

    expect($restored->scheme)->toBe($config->scheme);
    expect($restored->host)->toBe($config->host);
    expect($restored->port)->toBe($config->port);
    expect($restored->credential?->username)->toBe('user1');
    expect($restored->tls->verifyPeer)->toBeTrue();
    expect($restored->transportOptions)->toBeInstanceOf(SocksOptions::class);
});

it('round-trips HTTP config through JSON', function (): void {
    $config = makeHttpConfig();
    $json = $config->jsonSerialize();
    $restored = ProxyConfig::fromJson($json);

    expect($restored->scheme)->toBe($config->scheme);
    expect($restored->host)->toBe($config->host);
    expect($restored->port)->toBe($config->port);
    expect($restored->credential?->username)->toBeNull();
    expect($restored->tls->verifyPeer)->toBeFalse();
    expect($restored->tls->allowSelfSigned)->toBeTrue();
    expect($restored->transportOptions)->toBeInstanceOf(HttpConnectOptions::class);
});

it('round-trips MTProto config with null credential', function (): void {
    $config = makeMtprotoConfig();
    $json = $config->jsonSerialize();
    $restored = ProxyConfig::fromJson($json);

    expect($restored->credential)->toBeNull();
    expect($restored->transportOptions)->toBeInstanceOf(MtprotoOptions::class);
});

it('rejects unknown schemaVersion', function (): void {
    $json = makeSocks5Config()->jsonSerialize();
    $json['schemaVersion'] = 99;

    ProxyConfig::fromJson($json);
})->throws(RuntimeException::class, 'Unsupported ProxyConfig schemaVersion');

it('round-trips SocksOptions through JSON', function (): void {
    $options = new SocksOptions(enableUdpAssociate: true, dnsMode: SocksDnsMode::Remote);
    $json = $options->jsonSerialize();

    expect($json['schemaVersion'])->toBe(SocksOptions::SCHEMA_VERSION);
    expect($json['enableUdpAssociate'])->toBeTrue();
    expect($json['dnsMode'])->toBe('remote');

    $restored = SocksOptions::fromJson($json);

    expect($restored->enableUdpAssociate)->toBeTrue();
    expect($restored->dnsMode)->toBe(SocksDnsMode::Remote);
});

it('round-trips HttpConnectOptions through JSON', function (): void {
    $options = new HttpConnectOptions(tunnel: false);
    $json = $options->jsonSerialize();
    $restored = HttpConnectOptions::fromJson($json);

    expect($restored->tunnel)->toBeFalse();
});

it('round-trips MtprotoOptions through JSON', function (): void {
    $options = new MtprotoOptions;
    $json = $options->jsonSerialize();
    $restored = MtprotoOptions::fromJson($json);

    expect($restored)->toBeInstanceOf(MtprotoOptions::class);
});

it('round-trips TlsOptions through JSON', function (): void {
    $options = new TlsOptions(verifyPeer: false, allowSelfSigned: true, caBundlePath: '/etc/ssl/certs');
    $json = $options->jsonSerialize();
    $restored = TlsOptions::fromJson($json);

    expect($restored->verifyPeer)->toBeFalse();
    expect($restored->allowSelfSigned)->toBeTrue();
    expect($restored->caBundlePath)->toBe('/etc/ssl/certs');
});

it('round-trips TlsOptions defaults through JSON', function (): void {
    $options = new TlsOptions;
    $json = $options->jsonSerialize();
    $restored = TlsOptions::fromJson($json);

    expect($restored->verifyPeer)->toBeTrue();
    expect($restored->allowSelfSigned)->toBeFalse();
    expect($restored->caBundlePath)->toBeNull();
});

it('round-trips ProxyCredentialRef through JSON (StdinChannel)', function (): void {
    $ref = new ProxyCredentialRef(username: 'admin', channel: new StdinChannel);
    $json = $ref->jsonSerialize();
    $restored = ProxyCredentialRef::fromJson($json);

    expect($restored->username)->toBe('admin');
    expect($restored->channel)->toBeInstanceOf(StdinChannel::class);
});

it('round-trips ProxyCredentialRef through JSON (FileDescriptorChannel)', function (): void {
    $ref = new ProxyCredentialRef(username: null, channel: new FileDescriptorChannel(fileDescriptor: 5));
    $json = $ref->jsonSerialize();
    $restored = ProxyCredentialRef::fromJson($json);

    expect($restored->username)->toBeNull();
    expect($restored->channel)->toBeInstanceOf(FileDescriptorChannel::class);
});

it('round-trips SocksOptions defaults through JSON', function (): void {
    $options = new SocksOptions;
    $json = $options->jsonSerialize();
    $restored = SocksOptions::fromJson($json);

    expect($restored->enableUdpAssociate)->toBeFalse();
    expect($restored->dnsMode)->toBe(SocksDnsMode::Local);
});

it('SocksOptions rejects unknown schemaVersion', function (): void {
    $json = (new SocksOptions)->jsonSerialize();
    $json['schemaVersion'] = 99;

    SocksOptions::fromJson($json);
})->throws(RuntimeException::class, 'Unsupported SocksOptions schemaVersion');

it('HttpConnectOptions rejects unknown schemaVersion', function (): void {
    $json = (new HttpConnectOptions)->jsonSerialize();
    $json['schemaVersion'] = 99;

    HttpConnectOptions::fromJson($json);
})->throws(RuntimeException::class, 'Unsupported HttpConnectOptions schemaVersion');

it('TlsOptions rejects unknown schemaVersion', function (): void {
    $json = (new TlsOptions)->jsonSerialize();
    $json['schemaVersion'] = 99;

    TlsOptions::fromJson($json);
})->throws(RuntimeException::class, 'Unsupported TlsOptions schemaVersion');

it('ProxyCredentialRef rejects unknown schemaVersion', function (): void {
    $json = (new ProxyCredentialRef(username: null, channel: new StdinChannel))->jsonSerialize();
    $json['schemaVersion'] = 99;

    ProxyCredentialRef::fromJson($json);
})->throws(RuntimeException::class, 'Unsupported ProxyCredentialRef schemaVersion');
