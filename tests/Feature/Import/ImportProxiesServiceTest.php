<?php

declare(strict_types=1);

use App\Models\User;
use BAGArt\ProxyOperations\Domain\Identity\CredentialKind;
use BAGArt\ProxyOperations\Domain\Parsing\ImportProxiesCommand;
use BAGArt\ProxyOperations\Domain\Parsing\ImportResult;
use BAGArt\ProxyOperations\Models\ProxyAccess;
use BAGArt\ProxyOperations\Models\ProxyCredential;
use BAGArt\ProxyOperations\Models\ProxyEndpoint;
use BAGArt\ProxyOperations\Models\RawFeedEntry;
use BAGArt\ProxyOperations\Models\RawFeedEntryStatus;
use BAGArt\ProxyOperations\Parser\ImportProxiesService;
use BAGArt\ProxyOperations\Tenancy\TenantContext;
use BAGArt\ProxyOperations\Tenancy\TenantNotResolvedException;

function createImportTenantUser(): User
{
    return User::factory()->create();
}

function makeImportCommand(string $text, ?string $idempotencyKey = null): ImportProxiesCommand
{
    return new ImportProxiesCommand(
        text: $text,
        sourceLabel: 'test-import',
        tenantId: app(TenantContext::class)->id(),
        idempotencyKey: $idempotencyKey,
    );
}

it('creates endpoints, credentials, and accesses within the same tenant', function (): void {
    $user = createImportTenantUser();
    app(TenantContext::class)->set($user->id);

    $service = app(ImportProxiesService::class);
    $result = $service->execute(makeImportCommand("socks5://user:pass@1.2.3.4:1080\n1.2.3.5:1080"));

    expect($result->created)->toBe(2)
        ->and($result->skipped)->toBe(0)
        ->and($result->parseErrors)->toBe(0);

    $endpoints = ProxyEndpoint::query()->get();
    expect($endpoints)->toHaveCount(2);

    $accesses = ProxyAccess::query()->get();
    expect($accesses)->toHaveCount(2);
    foreach ($accesses as $access) {
        expect($access->state->value)->toBe('new')
            ->and($access->testability_status->value)->toBe('testable');
    }

    $credentialedAccess = $accesses->first(fn (ProxyAccess $a) => $a->credential_id !== null);
    expect($credentialedAccess)->not->toBeNull();

    $credential = ProxyCredential::query()->find($credentialedAccess->credential_id);
    expect($credential)->not->toBeNull()
        ->and($credential->kind)->toBe(CredentialKind::SocksAuth)
        ->and($credential->username)->toBe('user');
});

it('seals the credential secret into secret_envelope (not plaintext)', function (): void {
    $user = createImportTenantUser();
    app(TenantContext::class)->set($user->id);

    $service = app(ImportProxiesService::class);
    $service->execute(makeImportCommand('socks5://user:pass@1.2.3.4:1080'));

    $credential = ProxyCredential::query()->first();

    expect($credential->secret_envelope)->not->toBeNull()
        ->and(array_keys($credential->secret_envelope))->toBe(['key_version', 'algorithm', 'nonce', 'ciphertext', 'tag'])
        ->and(str_contains((string) json_encode($credential->secret_envelope), 'pass'))->toBeFalse();
});

it('skips duplicate endpoints within the same tenant on second import', function (): void {
    $user = createImportTenantUser();
    app(TenantContext::class)->set($user->id);

    $service = app(ImportProxiesService::class);
    $text = "socks5://user:pass@1.2.3.4:1080\n1.2.3.5:1080";

    $first = $service->execute(makeImportCommand($text));
    expect($first->created)->toBe(2)->and($first->skipped)->toBe(0);

    $second = $service->execute(makeImportCommand($text));
    expect($second->created)->toBe(0)->and($second->skipped)->toBe(2);

    expect(ProxyEndpoint::query()->count())->toBe(2);
});

it('deduplicates identical lines within the same batch', function (): void {
    $user = createImportTenantUser();
    app(TenantContext::class)->set($user->id);

    $service = app(ImportProxiesService::class);
    $result = $service->execute(makeImportCommand("1.2.3.4:1080\n1.2.3.4:1080"));

    expect($result->created)->toBe(1)
        ->and(ProxyEndpoint::query()->count())->toBe(1);
});

it('returns cached result for the same idempotency key', function (): void {
    $user = createImportTenantUser();
    app(TenantContext::class)->set($user->id);

    $service = app(ImportProxiesService::class);
    $first = $service->execute(makeImportCommand('1.2.3.4:1080', 'idem-key-1'));

    $second = $service->execute(makeImportCommand('1.2.3.4:1080', 'idem-key-1'));

    expect($second->importBatchId)->toBe($first->importBatchId)
        ->and($second->created)->toBe($first->created)
        ->and($second->skipped)->toBe($first->skipped);
});

it('throws TenantNotResolvedException when TenantContext is not set', function (): void {
    $service = app(ImportProxiesService::class);
    $service->execute(makeImportCommand('1.2.3.4:1080'));
})->throws(TenantNotResolvedException::class);

it('does not leak data across tenants', function (): void {
    $userA = createImportTenantUser();
    $userB = createImportTenantUser();

    app(TenantContext::class)->set($userA->id);
    $service = app(ImportProxiesService::class);
    $service->execute(makeImportCommand('socks5://user:pass@1.2.3.4:1080'));

    app(TenantContext::class)->set($userB->id);
    $result = $service->execute(makeImportCommand('socks5://user:pass@1.2.3.4:1080'));
    expect($result->created)->toBe(1);

    app(TenantContext::class)->set($userA->id);
    expect(ProxyEndpoint::query()->count())->toBe(1);

    app(TenantContext::class)->set($userB->id);
    expect(ProxyEndpoint::query()->count())->toBe(1);
});

it('propagates parser rejection errors correctly', function (): void {
    $user = createImportTenantUser();
    app(TenantContext::class)->set($user->id);

    $service = app(ImportProxiesService::class);
    $result = $service->execute(makeImportCommand("vless://secret@1.2.3.4:443\n1.2.3.5:1080"));

    expect($result->created)->toBe(1)
        ->and($result->parseErrors)->toBeGreaterThanOrEqual(1);

    $vpnError = collect($result->errors)->firstWhere('code', 'non_vpn_rejected');
    expect($vpnError)->not->toBeNull()
        ->and($vpnError->line)->toBe(1);
});

it('creates RawFeedEntry rows for all non-empty lines', function (): void {
    $user = createImportTenantUser();
    app(TenantContext::class)->set($user->id);

    $service = app(ImportProxiesService::class);
    $service->execute(makeImportCommand("1.2.3.4:1080\n1.2.3.5:1080"));

    $entries = RawFeedEntry::query()->get();
    expect($entries)->toHaveCount(2);
    foreach ($entries as $entry) {
        expect($entry->status)->toBe(RawFeedEntryStatus::Parsed);
    }
});

it('creates RawFeedEntry rows with Error status for invalid lines', function (): void {
    $user = createImportTenantUser();
    app(TenantContext::class)->set($user->id);

    $service = app(ImportProxiesService::class);
    $service->execute(makeImportCommand("vless://bad@1.2.3.4:443\n1.2.3.5:1080"));

    $errorEntries = RawFeedEntry::query()->where('status', RawFeedEntryStatus::Error)->get();
    expect($errorEntries)->toHaveCount(1)
        ->and($errorEntries->first()->parse_error_json)->not->toBeNull();
});

it('creates MTProto credential with correct kind', function (): void {
    $user = createImportTenantUser();
    app(TenantContext::class)->set($user->id);

    $service = app(ImportProxiesService::class);
    $secret = bin2hex(random_bytes(16));
    $result = $service->execute(makeImportCommand("mtproto://{$secret}@1.2.3.4:443"));

    expect($result->created)->toBe(1);

    $credential = ProxyCredential::query()->first();
    expect($credential->kind)->toBe(CredentialKind::MtprotoSecret)
        ->and($credential->username)->toBeNull();
});

it('returns structured ImportResult with correct counts', function (): void {
    $user = createImportTenantUser();
    app(TenantContext::class)->set($user->id);

    $service = app(ImportProxiesService::class);
    $result = $service->execute(makeImportCommand("socks5://u:p@1.2.3.4:1080\n1.2.3.5:1080\nvless://bad@host:443"));

    expect($result)->toBeInstanceOf(ImportResult::class)
        ->and($result->totalLines)->toBe(3)
        ->and($result->created)->toBe(2)
        ->and($result->parseErrors)->toBe(1)
        ->and($result->staged)->toBe(3)
        ->and($result->importBatchId)->not->toBeEmpty();
});

it('supports HTTP basic auth credentials', function (): void {
    $user = createImportTenantUser();
    app(TenantContext::class)->set($user->id);

    $service = app(ImportProxiesService::class);
    $result = $service->execute(makeImportCommand('http://admin:secret@1.2.3.4:8080'));

    expect($result->created)->toBe(1);

    $credential = ProxyCredential::query()->first();
    expect($credential->kind)->toBe(CredentialKind::BasicAuth)
        ->and($credential->username)->toBe('admin');
});

it('roundtrips ImportResult through jsonSerialize/fromJson', function (): void {
    $user = createImportTenantUser();
    app(TenantContext::class)->set($user->id);

    $service = app(ImportProxiesService::class);
    $original = $service->execute(makeImportCommand('1.2.3.4:1080'));

    $json = $original->jsonSerialize();
    $restored = ImportResult::fromJson($json);

    expect($restored->totalLines)->toBe($original->totalLines)
        ->and($restored->created)->toBe($original->created)
        ->and($restored->skipped)->toBe($original->skipped)
        ->and($restored->parseErrors)->toBe($original->parseErrors)
        ->and($restored->importBatchId)->toBe($original->importBatchId);
});

it('handles empty text gracefully', function (): void {
    $user = createImportTenantUser();
    app(TenantContext::class)->set($user->id);

    $service = app(ImportProxiesService::class);
    $result = $service->execute(makeImportCommand(''));

    expect($result->totalLines)->toBe(0)
        ->and($result->created)->toBe(0)
        ->and($result->staged)->toBe(0);
});

it('skips blank lines without creating feed entries', function (): void {
    $user = createImportTenantUser();
    app(TenantContext::class)->set($user->id);

    $service = app(ImportProxiesService::class);
    $result = $service->execute(makeImportCommand("1.2.3.4:1080\n\n1.2.3.5:1080"));

    expect($result->created)->toBe(2)
        ->and(RawFeedEntry::query()->count())->toBe(2);
});
