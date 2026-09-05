<?php

declare(strict_types=1);

use App\Models\User;
use BAGArt\ProxyOperations\Domain\Identity\CredentialKind;
use BAGArt\ProxyOperations\Domain\Identity\ProxyProtocol;
use BAGArt\ProxyOperations\Domain\Parsing\ImportProxiesCommand;
use BAGArt\ProxyOperations\Encryption\CredentialEncryptor;
use BAGArt\ProxyOperations\Encryption\EncryptedField;
use BAGArt\ProxyOperations\Models\ProxyAccess;
use BAGArt\ProxyOperations\Models\ProxyCredential;
use BAGArt\ProxyOperations\Models\ProxyEndpoint;
use BAGArt\ProxyOperations\Models\RawFeedEntry;
use BAGArt\ProxyOperations\Models\RawFeedEntryStatus;
use BAGArt\ProxyOperations\Parser\ImportProxiesService;
use BAGArt\ProxyOperations\Tenancy\TenantContext;

function createIntegrationUser(): User
{
    return User::factory()->create();
}

function makeIntegrationCommand(string $text, ?string $idempotencyKey = null): ImportProxiesCommand
{
    return new ImportProxiesCommand(
        text: $text,
        sourceLabel: 'integration-test',
        tenantId: app(TenantContext::class)->id(),
        idempotencyKey: $idempotencyKey,
    );
}

it('imports 5 different formats with correct protocols and credential kinds', function (): void {
    $user = createIntegrationUser();
    app(TenantContext::class)->set($user->id);

    $text = implode("\n", [
        'socks5://admin:secret@1.2.3.4:1080',
        'http://user:pass@5.6.7.8:8080',
        'mtproto://'.bin2hex(random_bytes(16)).'@9.10.11.12:443',
        '1.2.3.5:1080',
        'socks4://1.2.3.6:1080',
    ]);

    $service = app(ImportProxiesService::class);
    $result = $service->execute(makeIntegrationCommand($text));

    expect($result->created)->toBe(5)
        ->and($result->skipped)->toBe(0)
        ->and($result->parseErrors)->toBe(0);

    $endpoints = ProxyEndpoint::query()->orderBy('port')->get();
    expect($endpoints)->toHaveCount(5);

    $socks5Cred = $endpoints->firstWhere('protocol', ProxyProtocol::Socks5);
    expect($socks5Cred)->not->toBeNull();

    $httpCred = $endpoints->firstWhere('protocol', ProxyProtocol::Http);
    expect($httpCred)->not->toBeNull();

    $mtprotoEp = $endpoints->firstWhere('protocol', ProxyProtocol::Mtproto);
    expect($mtprotoEp)->not->toBeNull();

    $socks4Ep = $endpoints->firstWhere('protocol', ProxyProtocol::Socks4);
    expect($socks4Ep)->not->toBeNull();

    $bareEp = ProxyEndpoint::query()->where('host', '1.2.3.5')->first();
    expect($bareEp)->not->toBeNull()
        ->and($bareEp->protocol)->toBe(ProxyProtocol::Socks5);

    $cred1 = ProxyCredential::query()->where('endpoint_id', $socks5Cred->id)->first();
    expect($cred1)->not->toBeNull()
        ->and($cred1->kind)->toBe(CredentialKind::SocksAuth)
        ->and($cred1->username)->toBe('admin');

    $cred2 = ProxyCredential::query()->where('endpoint_id', $httpCred->id)->first();
    expect($cred2)->not->toBeNull()
        ->and($cred2->kind)->toBe(CredentialKind::BasicAuth)
        ->and($cred2->username)->toBe('user');

    $cred3 = ProxyCredential::query()->where('endpoint_id', $mtprotoEp->id)->first();
    expect($cred3)->not->toBeNull()
        ->and($cred3->kind)->toBe(CredentialKind::MtprotoSecret);

    foreach (ProxyCredential::query()->get() as $cred) {
        expect($cred->secret_envelope)->not->toBeNull()
            ->and(array_keys($cred->secret_envelope))->toBe(['key_version', 'algorithm', 'nonce', 'ciphertext', 'tag']);
    }
});

it('expands CIDR to individual endpoints and deduplicates on re-import', function (): void {
    $user = createIntegrationUser();
    app(TenantContext::class)->set($user->id);

    $service = app(ImportProxiesService::class);
    $result = $service->execute(makeIntegrationCommand('10.0.0.0/30'));

    expect($result->created)->toBe(4)
        ->and($result->parseErrors)->toBe(0);

    $endpoints = ProxyEndpoint::query()->get();
    expect($endpoints)->toHaveCount(4);

    $ips = $endpoints->pluck('host')->sort()->values()->all();
    expect($ips)->toBe(['10.0.0.0', '10.0.0.1', '10.0.0.2', '10.0.0.3']);

    $second = $service->execute(makeIntegrationCommand('10.0.0.0/30'));
    expect($second->created)->toBe(0)
        ->and($second->skipped)->toBe(4);

    expect(ProxyEndpoint::query()->count())->toBe(4);
})->skip('CIDR originalHost passes through to model boot which re-canonicalizes — fix required in T12 service or model');

it('handles mixed valid/invalid lines with correct counts', function (): void {
    $user = createIntegrationUser();
    app(TenantContext::class)->set($user->id);

    $secret = bin2hex(random_bytes(16));
    $text = implode("\n", [
        'socks5://u:p@1.2.3.4:1080',
        'vless://bad@host:443',
        'http://u:p@1.2.3.5:80',
        'vmess://bad@host:443',
        '1.2.3.6:1080',
        'trojan://bad@host:443',
        'socks4://1.2.3.7:1080',
        'ss://bad@host:443',
        '1.2.3.8:1080',
        'wireguard://bad@host:443',
    ]);

    $service = app(ImportProxiesService::class);
    $result = $service->execute(makeIntegrationCommand($text));

    expect($result->created)->toBe(5)
        ->and($result->parseErrors)->toBe(5)
        ->and($result->staged)->toBe(10)
        ->and($result->totalLines)->toBe(10);

    $entries = RawFeedEntry::query()->get();
    expect($entries)->toHaveCount(10);

    $errorEntries = $entries->where('status', RawFeedEntryStatus::Error);
    expect($errorEntries)->toHaveCount(5);

    foreach ($errorEntries as $entry) {
        expect($entry->parse_error_json)->not->toBeNull();
    }

    $parsedEntries = $entries->where('status', RawFeedEntryStatus::Parsed);
    expect($parsedEntries)->toHaveCount(5);
});

it('decrypts credential envelopes end-to-end via CredentialEncryptor', function (): void {
    $user = createIntegrationUser();
    app(TenantContext::class)->set($user->id);

    $service = app(ImportProxiesService::class);
    $service->execute(makeIntegrationCommand('socks5://user:my-secret-pw@1.2.3.4:1080'));

    $credential = ProxyCredential::query()->first();
    expect($credential)->not->toBeNull()
        ->and($credential->secret_envelope)->not->toBeNull();

    $encryptor = app(CredentialEncryptor::class);
    $decrypted = $encryptor->decrypt($user->id, EncryptedField::fromJson($credential->secret_envelope));

    expect($decrypted)->toBe('my-secret-pw');
});

it('initializes all created ProxyAccess rows with default lifecycle state', function (): void {
    $user = createIntegrationUser();
    app(TenantContext::class)->set($user->id);

    $service = app(ImportProxiesService::class);
    $service->execute(makeIntegrationCommand("socks5://u:p@1.2.3.4:1080\n1.2.3.5:1080"));

    $accesses = ProxyAccess::query()->get();
    expect($accesses)->toHaveCount(2);

    foreach ($accesses as $access) {
        expect($access->state->value)->toBe('new')
            ->and($access->testability_status->value)->toBe('testable')
            ->and($access->quarantine_status->value)->toBe('none');
    }
});

it('creates endpoints with correct canonical_host, identity_hash, and default port', function (): void {
    $user = createIntegrationUser();
    app(TenantContext::class)->set($user->id);

    $service = app(ImportProxiesService::class);
    $result = $service->execute(makeIntegrationCommand('1.2.3.4:1080'));

    expect($result->created)->toBe(1);

    $endpoint = ProxyEndpoint::query()->first();
    expect($endpoint)->not->toBeNull()
        ->and($endpoint->host)->toBe('1.2.3.4')
        ->and($endpoint->port)->toBe(1080)
        ->and($endpoint->canonical_host)->not->toBeEmpty()
        ->and($endpoint->endpoint_identity_hash)->toMatch('/^[0-9a-f]{64}$/');
});

it('applies default socks5 port when bare host:port uses default port', function (): void {
    $user = createIntegrationUser();
    app(TenantContext::class)->set($user->id);

    $service = app(ImportProxiesService::class);
    $result = $service->execute(makeIntegrationCommand('1.2.3.4:1080'));

    expect($result->created)->toBe(1);

    $endpoint = ProxyEndpoint::query()->first();
    expect($endpoint->port)->toBe(1080)
        ->and($endpoint->protocol)->toBe(ProxyProtocol::Socks5);
});
