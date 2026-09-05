<?php

declare(strict_types=1);

use App\Models\User;
use BAGArt\ProxyOperations\Domain\Parsing\ImportProxiesCommand;
use BAGArt\ProxyOperations\Models\RawFeedEntry;
use BAGArt\ProxyOperations\Models\RawFeedEntryStatus;
use BAGArt\ProxyOperations\Parser\ImportProxiesService;
use BAGArt\ProxyOperations\Tenancy\TenantContext;

function createGuardUser(): User
{
    return User::factory()->create();
}

function makeGuardCommand(string $text): ImportProxiesCommand
{
    return new ImportProxiesCommand(
        text: $text,
        sourceLabel: 'guard-test',
        tenantId: app(TenantContext::class)->id(),
        idempotencyKey: null,
    );
}

it('rejects vless with NonVpnRejected', function (): void {
    $user = createGuardUser();
    app(TenantContext::class)->set($user->id);

    $service = app(ImportProxiesService::class);
    $result = $service->execute(makeGuardCommand('vless://secret@1.2.3.4:443'));

    expect($result->created)->toBe(0)
        ->and($result->parseErrors)->toBe(1);

    $error = collect($result->errors)->firstWhere('code', 'non_vpn_rejected');
    expect($error)->not->toBeNull()
        ->and($error->line)->toBe(1);
});

it('rejects vmess with NonVpnRejected', function (): void {
    $user = createGuardUser();
    app(TenantContext::class)->set($user->id);

    $service = app(ImportProxiesService::class);
    $result = $service->execute(makeGuardCommand('vmess://secret@1.2.3.4:443'));

    expect($result->parseErrors)->toBe(1);
    expect(collect($result->errors)->firstWhere('code', 'non_vpn_rejected'))->not->toBeNull();
});

it('rejects trojan with NonVpnRejected', function (): void {
    $user = createGuardUser();
    app(TenantContext::class)->set($user->id);

    $service = app(ImportProxiesService::class);
    $result = $service->execute(makeGuardCommand('trojan://secret@1.2.3.4:443'));

    expect($result->parseErrors)->toBe(1);
    expect(collect($result->errors)->firstWhere('code', 'non_vpn_rejected'))->not->toBeNull();
});

it('rejects ss with NonVpnRejected', function (): void {
    $user = createGuardUser();
    app(TenantContext::class)->set($user->id);

    $service = app(ImportProxiesService::class);
    $result = $service->execute(makeGuardCommand('ss://secret@1.2.3.4:443'));

    expect($result->parseErrors)->toBe(1);
    expect(collect($result->errors)->firstWhere('code', 'non_vpn_rejected'))->not->toBeNull();
});

it('rejects wireguard with NonVpnRejected', function (): void {
    $user = createGuardUser();
    app(TenantContext::class)->set($user->id);

    $service = app(ImportProxiesService::class);
    $result = $service->execute(makeGuardCommand('wireguard://secret@1.2.3.4:443'));

    expect($result->parseErrors)->toBe(1);
    expect(collect($result->errors)->firstWhere('code', 'non_vpn_rejected'))->not->toBeNull();
});

it('rejects openvpn with NonVpnRejected', function (): void {
    $user = createGuardUser();
    app(TenantContext::class)->set($user->id);

    $service = app(ImportProxiesService::class);
    $result = $service->execute(makeGuardCommand('openvpn://secret@1.2.3.4:443'));

    expect($result->parseErrors)->toBe(1);
    expect(collect($result->errors)->firstWhere('code', 'non_vpn_rejected'))->not->toBeNull();
});

it('creates RawFeedEntry rows with Error status for VPN-rejected lines', function (): void {
    $user = createGuardUser();
    app(TenantContext::class)->set($user->id);

    $service = app(ImportProxiesService::class);
    $service->execute(makeGuardCommand('vless://bad@1.2.3.4:443'));

    $entries = RawFeedEntry::query()->get();
    expect($entries)->toHaveCount(1)
        ->and($entries->first()->status)->toBe(RawFeedEntryStatus::Error)
        ->and($entries->first()->parse_error_json)->not->toBeNull();
});

it('imports valid lines while rejecting VPN lines in mixed input', function (): void {
    $user = createGuardUser();
    app(TenantContext::class)->set($user->id);

    $service = app(ImportProxiesService::class);
    $result = $service->execute(makeGuardCommand("vless://bad@host:443\n1.2.3.4:1080\nvmess://bad@host:443\n1.2.3.5:1080"));

    expect($result->created)->toBe(2)
        ->and($result->parseErrors)->toBe(2);

    $entries = RawFeedEntry::query()->get();
    expect($entries)->toHaveCount(4);

    $errorCount = $entries->where('status', RawFeedEntryStatus::Error)->count();
    $parsedCount = $entries->where('status', RawFeedEntryStatus::Parsed)->count();
    expect($errorCount)->toBe(2)
        ->and($parsedCount)->toBe(2);
});
