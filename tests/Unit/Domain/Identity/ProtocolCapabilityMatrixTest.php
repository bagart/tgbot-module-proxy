<?php

declare(strict_types=1);

use BAGArt\ProxyOperations\Domain\Identity\ApplicationCapability;
use BAGArt\ProxyOperations\Domain\Identity\ProtocolCapabilityMatrix;
use BAGArt\ProxyOperations\Domain\Identity\ProxyProtocol;
use BAGArt\ProxyOperations\Domain\Identity\TransportKind;

beforeEach(fn (): object => $this->matrix = new ProtocolCapabilityMatrix);

it('matches plan §11.4 transport rows', function (ProxyProtocol $protocol, array $transports): void {
    expect($this->matrix->transportsFor($protocol))->toEqual($transports);
})->with([
    [ProxyProtocol::Http, [TransportKind::TcpDirect, TransportKind::TcpViaProxy]],
    [ProxyProtocol::Https, [TransportKind::TcpDirect, TransportKind::TcpViaProxy]],
    [ProxyProtocol::Socks4, [TransportKind::TcpDirect, TransportKind::TcpViaProxy]],
    [ProxyProtocol::Socks4a, [TransportKind::TcpDirect, TransportKind::TcpViaProxy]],
    [ProxyProtocol::Socks5, [
        TransportKind::TcpDirect,
        TransportKind::TcpViaProxy,
        TransportKind::UdpAssociate,
        TransportKind::DnsModes,
    ]],
    [ProxyProtocol::Socks5h, [
        TransportKind::TcpDirect,
        TransportKind::TcpViaProxy,
        TransportKind::UdpAssociate,
        TransportKind::DnsModes,
    ]],
    [ProxyProtocol::Mtproto, [TransportKind::TcpDirect]],
]);

it('gives MTProto no proxy-transport capability — it is an application protocol over direct TCP', function (): void {
    expect($this->matrix->supportsTransport(ProxyProtocol::Mtproto, TransportKind::TcpViaProxy))->toBeFalse()
        ->and($this->matrix->supportsTransport(ProxyProtocol::Mtproto, TransportKind::UdpAssociate))->toBeFalse()
        ->and($this->matrix->supportsTransport(ProxyProtocol::Mtproto, TransportKind::DnsModes))->toBeFalse()
        ->and($this->matrix->supports(ProxyProtocol::Mtproto, ApplicationCapability::HttpTargets))->toBeFalse()
        ->and($this->matrix->supports(ProxyProtocol::Mtproto, ApplicationCapability::HttpsViaConnect))->toBeFalse()
        ->and($this->matrix->supports(ProxyProtocol::Mtproto, ApplicationCapability::TelegramConnectivity))->toBeTrue();
});

it('resolves DNS on the proxy side only for SOCKS5 family', function (ProxyProtocol $protocol, bool $expected): void {
    expect($this->matrix->supports($protocol, ApplicationCapability::RemoteDns))->toBe($expected);
})->with([
    [ProxyProtocol::Http, false],
    [ProxyProtocol::Https, false],
    [ProxyProtocol::Socks4, false],
    [ProxyProtocol::Socks4a, false],
    [ProxyProtocol::Socks5, true],
    [ProxyProtocol::Socks5h, true],
    [ProxyProtocol::Mtproto, false],
]);

it('restricts UDP ASSOCIATE to SOCKS5 family', function (): void {
    foreach (ProxyProtocol::cases() as $protocol) {
        $expected = in_array($protocol, [ProxyProtocol::Socks5, ProxyProtocol::Socks5h], true);
        expect($this->matrix->supports($protocol, ApplicationCapability::UdpAssociate))->toBe($expected);
    }
});

it('keeps UDP ASSOCIATE and remote DNS as independent capabilities', function (): void {
    expect($this->matrix->supports(ProxyProtocol::Socks5h, ApplicationCapability::UdpAssociate))->toBeTrue()
        ->and($this->matrix->supports(ProxyProtocol::Socks5h, ApplicationCapability::RemoteDns))->toBeTrue()
        ->and($this->matrix->supports(ProxyProtocol::Https, ApplicationCapability::HttpsViaConnect))->toBeTrue()
        ->and($this->matrix->supports(ProxyProtocol::Https, ApplicationCapability::UdpAssociate))->toBeFalse()
        ->and($this->matrix->supports(ProxyProtocol::Https, ApplicationCapability::RemoteDns))->toBeFalse();
});
