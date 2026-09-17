<?php

declare(strict_types=1);

use App\Models\User;
use BAGArt\ProxyOperations\Application\ApplicationServiceBus;
use BAGArt\ProxyOperations\Application\ExportInventoryCommand;
use BAGArt\ProxyOperations\Application\ImportProxiesCommand;
use BAGArt\ProxyOperations\Application\ImportSource;
use BAGArt\ProxyOperations\Application\StartAuditCommand;
use BAGArt\ProxyOperations\Application\WorkspaceSettingsQuery;
use BAGArt\ProxyOperations\Application\UpdateSettingsCommand;
use BAGArt\ProxyOperations\Models\ProxyAccess;
use BAGArt\ProxyOperations\Tenancy\TenantContext;

/**
 * Multi-interface consistency test (T63): same data through different interfaces → consistent state.
 */
it('imported via application service is visible via export and settings persist', function (): void {
    $user = User::factory()->create();
    $tenantId = (string) $user->id;
    app(TenantContext::class)->set($user->id);

    $bus = app(ApplicationServiceBus::class);

    // 1. Import.
    $bus->dispatch(new ImportProxiesCommand(
        tenantId: $tenantId,
        source: ImportSource::Paste,
        payload: "socks5://user:pass@10.0.0.1:1080\n",
        sourceLabel: 'consistency-test',
    ));

    // 2. Verify imported data is visible in the model.
    $accesses = ProxyAccess::where('tenant_id', $tenantId)->get();
    expect($accesses->count())->toBeGreaterThanOrEqual(1);

    // 3. Export should include the imported proxy.
    $exportResult = $bus->dispatch(new ExportInventoryCommand(
        tenantId: $tenantId,
        format: 'json',
        requestedBy: 'consistency-test',
    ));

    expect($exportResult->success)->toBeTrue();
    expect($exportResult->data['record_count'])->toBeGreaterThanOrEqual(1);

    // 4. Settings update persists and is readable.
    $bus->dispatch(new UpdateSettingsCommand(
        tenantId: $tenantId,
        field: 'max_endpoints',
        value: 9999,
    ));

    $settingsResult = $bus->query(new WorkspaceSettingsQuery(tenantId: $tenantId));
    expect($settingsResult->found)->toBeTrue();
    expect($settingsResult->data['max_endpoints'])->toBe(9999);

    app(TenantContext::class)->forget();
})->skip(fn () => ! class_exists(User::class), 'App\\Models\\User not available');
