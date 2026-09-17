<?php

declare(strict_types=1);

use App\Models\User;
use BAGArt\ProxyOperations\Application\ApplicationServiceBus;
use BAGArt\ProxyOperations\Application\ExportInventoryCommand;
use BAGArt\ProxyOperations\Application\ImportProxiesCommand;
use BAGArt\ProxyOperations\Application\ImportSource;
use BAGArt\ProxyOperations\Application\WorkspaceSettingsQuery;
use BAGArt\ProxyOperations\Application\UpdateSettingsCommand;
use BAGArt\ProxyOperations\Models\ProxyAccess;
use BAGArt\ProxyOperations\Tenancy\TenantContext;

/**
 * Tenant isolation E2E test (T63): two workspaces see only their own data.
 */
it('two workspaces each see only their own imported proxies', function (): void {
    // Workspace A.
    $userA = User::factory()->create();
    $tenantA = (string) $userA->id;
    app(TenantContext::class)->set($userA->id);

    $bus = app(ApplicationServiceBus::class);

    $bus->dispatch(new ImportProxiesCommand(
        tenantId: $tenantA,
        source: ImportSource::Paste,
        payload: "socks5://a:a@10.0.0.1:1080\nsocks5://a2:a2@10.0.0.2:1080\n",
        sourceLabel: 'tenant-a',
    ));

    $countA = ProxyAccess::where('tenant_id', $tenantA)->count();
    expect($countA)->toBe(2);

    // Workspace B.
    $userB = User::factory()->create();
    $tenantB = (string) $userB->id;
    app(TenantContext::class)->set($userB->id);

    $bus->dispatch(new ImportProxiesCommand(
        tenantId: $tenantB,
        source: ImportSource::Paste,
        payload: "http://b:b@10.0.0.3:8080\n",
        sourceLabel: 'tenant-b',
    ));

    $countB = ProxyAccess::where('tenant_id', $tenantB)->count();
    expect($countB)->toBe(1);

    // Cross-tenant check: A cannot see B's proxies via export.
    app(TenantContext::class)->set($userA->id);

    $exportA = $bus->dispatch(new ExportInventoryCommand(
        tenantId: $tenantA,
        format: 'json',
        requestedBy: 'isolation-test',
    ));

    expect($exportA->success)->toBeTrue();
    // Export should only contain tenant A's proxies (2).
    expect($exportA->data['record_count'])->toBe(2);

    // Cross-tenant settings: A's settings are independent of B's.
    $bus->dispatch(new UpdateSettingsCommand(
        tenantId: $tenantA,
        field: 'max_endpoints',
        value: 1111,
    ));

    $settingsA = $bus->query(new WorkspaceSettingsQuery(tenantId: $tenantA));
    expect($settingsA->data['max_endpoints'])->toBe(1111);

    app(TenantContext::class)->set($userB->id);
    $settingsB = $bus->query(new WorkspaceSettingsQuery(tenantId: $tenantB));
    expect($settingsB->data['max_endpoints'])->toBe(1000); // Default.

    app(TenantContext::class)->forget();
})->skip(fn () => ! class_exists(User::class), 'App\\Models\\User not available');
