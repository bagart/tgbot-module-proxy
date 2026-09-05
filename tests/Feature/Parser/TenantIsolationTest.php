<?php

declare(strict_types=1);

use App\Models\User;
use BAGArt\ProxyOperations\Domain\Parsing\ImportProxiesCommand;
use BAGArt\ProxyOperations\Encryption\CredentialEncryptor;
use BAGArt\ProxyOperations\Encryption\EncryptedField;
use BAGArt\ProxyOperations\Models\ProxyCredential;
use BAGArt\ProxyOperations\Models\ProxyEndpoint;
use BAGArt\ProxyOperations\Models\RawFeedEntry;
use BAGArt\ProxyOperations\Parser\ImportProxiesService;
use BAGArt\ProxyOperations\Tenancy\TenantContext;
use BAGArt\ProxyOperations\Tenancy\TenantNotResolvedException;
use RuntimeException;

function createTenantIsolationUser(): User
{
    return User::factory()->create();
}

function makeTenantCommand(string $text): ImportProxiesCommand
{
    return new ImportProxiesCommand(
        text: $text,
        sourceLabel: 'tenant-test',
        tenantId: app(TenantContext::class)->id(),
        idempotencyKey: null,
    );
}

it('import as tenant A is invisible to tenant B queries on ProxyEndpoint', function (): void {
    $userA = createTenantIsolationUser();
    $userB = createTenantIsolationUser();

    app(TenantContext::class)->set($userA->id);
    $service = app(ImportProxiesService::class);
    $service->execute(makeTenantCommand('socks5://u:p@1.2.3.4:1080'));

    app(TenantContext::class)->set($userB->id);
    expect(ProxyEndpoint::query()->count())->toBe(0);

    app(TenantContext::class)->set($userA->id);
    expect(ProxyEndpoint::query()->count())->toBe(1);
});

it('import as tenant A is invisible to tenant B queries on RawFeedEntry', function (): void {
    $userA = createTenantIsolationUser();
    $userB = createTenantIsolationUser();

    app(TenantContext::class)->set($userA->id);
    $service = app(ImportProxiesService::class);
    $service->execute(makeTenantCommand('1.2.3.4:1080'));

    app(TenantContext::class)->set($userB->id);
    expect(RawFeedEntry::query()->count())->toBe(0);

    app(TenantContext::class)->set($userA->id);
    expect(RawFeedEntry::query()->count())->toBe(1);
});

it('two tenants importing the same text create independent endpoints', function (): void {
    $userA = createTenantIsolationUser();
    $userB = createTenantIsolationUser();

    app(TenantContext::class)->set($userA->id);
    $service = app(ImportProxiesService::class);
    $service->execute(makeTenantCommand('socks5://u:p@1.2.3.4:1080'));

    app(TenantContext::class)->set($userB->id);
    $service->execute(makeTenantCommand('socks5://u:p@1.2.3.4:1080'));

    app(TenantContext::class)->set($userA->id);
    $epA = ProxyEndpoint::query()->first();
    $hashA = $epA->endpoint_identity_hash;

    app(TenantContext::class)->set($userB->id);
    $epB = ProxyEndpoint::query()->first();

    expect($epB->endpoint_identity_hash)->toBe($hashA)
        ->and($epB->tenant_id)->toBe($userB->id)
        ->and($epB->id)->not->toBe($epA->id);

    app(TenantContext::class)->set($userA->id);
    expect(ProxyEndpoint::query()->count())->toBe(1);

    app(TenantContext::class)->set($userB->id);
    expect(ProxyEndpoint::query()->count())->toBe(1);
});

it('credential from tenant A is not decryptable by tenant B DEK', function (): void {
    $userA = createTenantIsolationUser();
    $userB = createTenantIsolationUser();

    app(TenantContext::class)->set($userA->id);
    $service = app(ImportProxiesService::class);
    $service->execute(makeTenantCommand('socks5://user:secretA@1.2.3.4:1080'));

    $credential = ProxyCredential::query()->first();
    $envelope = EncryptedField::fromJson($credential->secret_envelope);

    $encryptorA = app(CredentialEncryptor::class);
    $decryptedA = $encryptorA->decrypt($userA->id, $envelope);
    expect($decryptedA)->toBe('secretA');

    $encryptorB = app(CredentialEncryptor::class);
    $encryptorB->decrypt($userB->id, $envelope);
})->throws(RuntimeException::class);

it('throws TenantNotResolvedException when called without TenantContext', function (): void {
    $service = app(ImportProxiesService::class);
    $service->execute(makeTenantCommand('1.2.3.4:1080'));
})->throws(TenantNotResolvedException::class);
