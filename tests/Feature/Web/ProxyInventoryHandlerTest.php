<?php

declare(strict_types=1);

use App\Models\User;
use BAGArt\ProxyOperations\Domain\Lifecycle\QuarantineStatus;
use BAGArt\ProxyOperations\Models\ProxyAccess;
use BAGArt\ProxyOperations\Models\ProxyEndpoint;
use BAGArt\ProxyOperations\Tenancy\TenantContext;
use BAGArt\ProxyOperations\Web\ProxyInventoryHandler;
use BAGArt\TelegramBotMenu\Manifest\EffectiveRole;
use BAGArt\TelegramBotMenu\Support\BotRef;
use BAGArt\TelegramBotMenu\Support\ModuleRef;
use BAGArt\TelegramBotMenu\Support\TgUiContext;
use BAGArt\TelegramBotMenu\Support\TgWebRequest;
use BAGArt\TelegramBotMenu\Support\UserRef;

/**
 * menu_integration.md M-6 slice 2: the hub inventory read. Tenant = the hub
 * user; scoping negatives and the forget-after-call guarantee are the point
 * of the slice (module hard rule: masked counts only, no hosts).
 */
function proxyWebRequest(int $userId): TgWebRequest
{
    $context = new TgUiContext(
        bot: new BotRef('7010', 'proxybot'),
        chat: null,
        module: new ModuleRef('proxy'),
        role: EffectiveRole::Admin,
        user: new UserRef($userId, 'Owner', 'en'),
    );

    return new TgWebRequest(
        botId: '7010',
        tgUserId: $userId,
        role: EffectiveRole::Admin,
        chatId: null,
        locale: 'en',
        payload: [],
        requestId: 'req-1',
        context: $context,
    );
}

it('returns a tenant-scoped, masked inventory summary', function (): void {
    // Tenant A: two endpoints, one working, one quarantined.
    $tenantA = User::factory()->create()->id;
    app(TenantContext::class)->set($tenantA);
    $working = ProxyEndpoint::factory()->create();
    ProxyAccess::factory()->working()->create(['endpoint_id' => $working->id]);
    $quarantined = ProxyEndpoint::factory()->create();
    ProxyAccess::factory()->create([
        'endpoint_id' => $quarantined->id,
        'quarantine_status' => QuarantineStatus::Quarantined->value,
    ]);
    app(TenantContext::class)->forget();

    // Tenant B: one endpoint the first tenant must never see.
    $tenantB = User::factory()->create()->id;
    app(TenantContext::class)->set($tenantB);
    $foreign = ProxyEndpoint::factory()->create();
    ProxyAccess::factory()->dead()->create(['endpoint_id' => $foreign->id]);
    app(TenantContext::class)->forget();

    $response = (new ProxyInventoryHandler())
        ->handle(proxyWebRequest($tenantA), ['inventory']);

    expect($response->status)->toBe(200)
        ->and($response->body['data']['endpoints'])->toBe(2)
        ->and($response->body['data']['states']['working'] ?? 0)->toBe(1)
        ->and($response->body['data']['states']['dead'] ?? 0)->toBe(0)
        ->and($response->body['data']['quarantined'])->toBe(1)
        // Masked surface: no hosts or credentials in any shape.
        ->and(json_encode($response->body))->not->toContain($working->host)
        ->and(json_encode($response->body))->not->toContain($foreign->host)
        ->and(json_encode($response->body))->not->toContain('password');
});

it('scopes strictly per tenant and forgets the context after the call', function (): void {
    $tenantB = User::factory()->create()->id;
    app(TenantContext::class)->set($tenantB);
    $foreign = ProxyEndpoint::factory()->create();
    app(TenantContext::class)->forget();

    $response = (new ProxyInventoryHandler())
        ->handle(proxyWebRequest(User::factory()->create()->id), ['inventory']);

    expect($response->status)->toBe(200)
        ->and($response->body['data']['endpoints'])->toBe(0)
        ->and(app(TenantContext::class)->tryId())->toBeNull();

    // Sanity: the foreign endpoint really exists under its own tenant.
    app(TenantContext::class)->set($tenantB);
    expect(ProxyEndpoint::query()->whereKey($foreign->id)->exists())->toBeTrue();
    app(TenantContext::class)->forget();
});

it('answers 404 for unknown routes', function (): void {
    $response = (new ProxyInventoryHandler())
        ->handle(proxyWebRequest(User::factory()->create()->id), ['unknown']);

    expect($response->status)->toBe(404)
        ->and($response->body['error']['code'])->toBe('not_found');
});
