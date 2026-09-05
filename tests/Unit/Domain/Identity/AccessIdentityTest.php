<?php

declare(strict_types=1);

use BAGArt\ProxyOperations\Domain\Identity\AccessIdentity;
use BAGArt\ProxyOperations\Domain\Identity\CredentialFingerprint;
use BAGArt\ProxyOperations\Domain\Identity\EndpointIdentity;
use BAGArt\ProxyOperations\Domain\Identity\ProxyProtocol;

const ACCESS_KEY = "\x11\x22binary-access-key";

function accessEndpoint(): EndpointIdentity
{
    return new EndpointIdentity('1.2.3.4', 1080, ProxyProtocol::Socks5);
}

it('derives a stable key from endpoint plus credential fingerprint', function (): void {
    $identity = new AccessIdentity(accessEndpoint(), CredentialFingerprint::fromUserPass(ACCESS_KEY, 'user', 'pass'));

    expect($identity->key())->toBe($identity->key())
        ->and($identity->key())->toMatch('/^[0-9a-f]{64}$/');
});

it('separates anonymous and authenticated accesses on one endpoint', function (): void {
    $anonymous = new AccessIdentity(
        accessEndpoint(),
        CredentialFingerprint::fromUserPass(ACCESS_KEY, 'user', 'pass'),
    );
    $authenticated = new AccessIdentity(accessEndpoint(), CredentialFingerprint::fromUserPass(ACCESS_KEY, 'other', 'other'));

    expect($anonymous->key())->not->toBe($authenticated->key());
});

it('is sensitive to both endpoint and credentials', function (): void {
    $base = new AccessIdentity(accessEndpoint(), CredentialFingerprint::fromUserPass(ACCESS_KEY, 'user', 'pass'));

    $otherHost = new AccessIdentity(
        new EndpointIdentity('1.2.3.5', 1080, ProxyProtocol::Socks5),
        $base->credential,
    );
    $otherCredential = new AccessIdentity(
        accessEndpoint(),
        new CredentialFingerprint(str_repeat('b', 64)),
    );

    expect($base->key())->not->toBe($otherHost->key())
        ->and($base->key())->not->toBe($otherCredential->key());
});

it('round-trips through JSON', function (): void {
    $identity = new AccessIdentity(accessEndpoint(), CredentialFingerprint::fromUserPass(ACCESS_KEY, 'user', 'pass'));
    $restored = AccessIdentity::fromJson(json_decode(json_encode($identity), true));

    expect($restored->endpoint)->toEqual($identity->endpoint)
        ->and($restored->credential)->toEqual($identity->credential)
        ->and($restored->key())->toBe($identity->key());
});

it('rejects unknown schema versions', function (): void {
    $payload = json_decode(json_encode(new AccessIdentity(
        accessEndpoint(),
        CredentialFingerprint::fromUserPass(ACCESS_KEY, 'user', 'pass'),
    )), true);
    $payload['schemaVersion'] = 99;

    AccessIdentity::fromJson($payload);
})->throws(RuntimeException::class, 'Unsupported AccessIdentity schemaVersion');
