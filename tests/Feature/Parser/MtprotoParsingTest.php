<?php

declare(strict_types=1);

use App\Models\User;
use BAGArt\ProxyOperations\Domain\Identity\CredentialKind;
use BAGArt\ProxyOperations\Domain\Identity\ProxyProtocol;
use BAGArt\ProxyOperations\Domain\Parsing\ImportProxiesCommand;
use BAGArt\ProxyOperations\Models\ProxyCredential;
use BAGArt\ProxyOperations\Models\ProxyEndpoint;
use BAGArt\ProxyOperations\Parser\ImportProxiesService;
use BAGArt\ProxyOperations\Tenancy\TenantContext;

function createMtprotoUser(): User
{
    return User::factory()->create();
}

function makeMtprotoCommand(string $text): ImportProxiesCommand
{
    return new ImportProxiesCommand(
        text: $text,
        sourceLabel: 'mtproto-test',
        tenantId: app(TenantContext::class)->id(),
        idempotencyKey: null,
    );
}

it('parses mtproto://HEXSECRET@host:port', function (): void {
    $user = createMtprotoUser();
    app(TenantContext::class)->set($user->id);

    $secret = bin2hex(random_bytes(16));
    $service = app(ImportProxiesService::class);
    $result = $service->execute(makeMtprotoCommand("mtproto://{$secret}@1.2.3.4:443"));

    expect($result->created)->toBe(1);

    $endpoint = ProxyEndpoint::query()->first();
    expect($endpoint->protocol)->toBe(ProxyProtocol::Mtproto)
        ->and($endpoint->port)->toBe(443);

    $credential = ProxyCredential::query()->first();
    expect($credential->kind)->toBe(CredentialKind::MtprotoSecret)
        ->and($credential->username)->toBeNull();
});

it('parses tg://proxy?server=host&port=port&secret=SECRET', function (): void {
    $user = createMtprotoUser();
    app(TenantContext::class)->set($user->id);

    $secret = bin2hex(random_bytes(16));
    $service = app(ImportProxiesService::class);
    $result = $service->execute(makeMtprotoCommand("tg://proxy?server=1.2.3.4&port=443&secret={$secret}"));

    expect($result->created)->toBe(1);

    $endpoint = ProxyEndpoint::query()->first();
    expect($endpoint->protocol)->toBe(ProxyProtocol::Mtproto)
        ->and($endpoint->host)->toBe('1.2.3.4')
        ->and($endpoint->port)->toBe(443);

    $credential = ProxyCredential::query()->first();
    expect($credential->kind)->toBe(CredentialKind::MtprotoSecret);
});

it('parses https://t.me/proxy?server=host&port=port&secret=SECRET', function (): void {
    $user = createMtprotoUser();
    app(TenantContext::class)->set($user->id);

    $secret = bin2hex(random_bytes(16));
    $service = app(ImportProxiesService::class);
    $result = $service->execute(makeMtprotoCommand("https://t.me/proxy?server=1.2.3.4&port=443&secret={$secret}"));

    expect($result->created)->toBe(1);

    $endpoint = ProxyEndpoint::query()->first();
    expect($endpoint->protocol)->toBe(ProxyProtocol::Mtproto);

    $credential = ProxyCredential::query()->first();
    expect($credential->kind)->toBe(CredentialKind::MtprotoSecret);
});

it('parses FakeTLS secret (hex with ee prefix) correctly', function (): void {
    $user = createMtprotoUser();
    app(TenantContext::class)->set($user->id);

    $fakeTlsSecret = 'ee'.bin2hex(random_bytes(16));
    $service = app(ImportProxiesService::class);
    $result = $service->execute(makeMtprotoCommand("mtproto://{$fakeTlsSecret}@1.2.3.4:443"));

    expect($result->created)->toBe(1);

    $credential = ProxyCredential::query()->first();
    expect($credential->kind)->toBe(CredentialKind::MtprotoSecret);
});

it('rejects invalid MTProto secret (non-hex)', function (): void {
    $user = createMtprotoUser();
    app(TenantContext::class)->set($user->id);

    $service = app(ImportProxiesService::class);
    $result = $service->execute(makeMtprotoCommand('mtproto://not-a-valid-hex-secret!@1.2.3.4:443'));

    expect($result->created)->toBe(0)
        ->and($result->parseErrors)->toBe(1);

    $error = collect($result->errors)->firstWhere('code', 'invalid_mtproto_secret');
    expect($error)->not->toBeNull();
});

it('MTProto endpoints default to port 443', function (): void {
    $user = createMtprotoUser();
    app(TenantContext::class)->set($user->id);

    $secret = bin2hex(random_bytes(16));
    $service = app(ImportProxiesService::class);
    $result = $service->execute(makeMtprotoCommand("mtproto://{$secret}@1.2.3.4:443"));

    expect($result->created)->toBe(1);

    $endpoint = ProxyEndpoint::query()->first();
    expect($endpoint->port)->toBe(443)
        ->and($endpoint->protocol)->toBe(ProxyProtocol::Mtproto);
});

it('parses mtproto:// with +r prefix secret', function (): void {
    $user = createMtprotoUser();
    app(TenantContext::class)->set($user->id);

    $secret = '+r'.bin2hex(random_bytes(16));
    $service = app(ImportProxiesService::class);
    $result = $service->execute(makeMtprotoCommand("mtproto://{$secret}@1.2.3.4:443"));

    expect($result->created)->toBe(1);

    $credential = ProxyCredential::query()->first();
    expect($credential->kind)->toBe(CredentialKind::MtprotoSecret);
});

it('rejects MTProto secret that is too short', function (): void {
    $user = createMtprotoUser();
    app(TenantContext::class)->set($user->id);

    $shortSecret = bin2hex(random_bytes(8));
    $service = app(ImportProxiesService::class);
    $result = $service->execute(makeMtprotoCommand("mtproto://{$shortSecret}@1.2.3.4:443"));

    expect($result->parseErrors)->toBe(1);
    $error = collect($result->errors)->firstWhere('code', 'invalid_mtproto_secret');
    expect($error)->not->toBeNull();
});
