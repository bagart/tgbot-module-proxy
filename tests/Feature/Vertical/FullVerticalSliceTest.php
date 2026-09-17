<?php

declare(strict_types=1);

use App\Models\User;
use BAGArt\ProxyOperations\Application\ApplicationServiceBus;
use BAGArt\ProxyOperations\Application\ExportInventoryCommand;
use BAGArt\ProxyOperations\Application\ImportProxiesCommand;
use BAGArt\ProxyOperations\Application\ImportSource;
use BAGArt\ProxyOperations\Application\StartAuditCommand;
use BAGArt\ProxyOperations\Application\WorkspaceSettingsQuery;
use BAGArt\ProxyOperations\Audit\PoolRepository;
use BAGArt\ProxyOperations\Models\ProxyAccess;
use BAGArt\ProxyOperations\Models\ProxyEndpoint;
use BAGArt\ProxyOperations\Tenancy\TenantContext;

/**
 * Full vertical slice test (T63): import → inventory → audit → export pipeline.
 */
function verticalSliceTenant(): string
{
    $user = User::factory()->create();
    app(TenantContext::class)->set($user->id);

    return (string) $user->id;
}

it('completes the full import → list → check → export slice via application services', function (): void {
    $tenantId = verticalSliceTenant();
    $bus = app(ApplicationServiceBus::class);

    // 1. Import via application service.
    $importResult = $bus->dispatch(new ImportProxiesCommand(
        tenantId: $tenantId,
        source: ImportSource::Paste,
        payload: "socks5://user:pass@10.0.0.1:1080\nhttp://proxy:8080@10.0.0.2:3128\n",
        sourceLabel: 'vertical-test',
    ));

    expect($importResult->success)->toBeTrue();

    // 2. List — at least one proxy should exist.
    $proxyCount = ProxyAccess::where('tenant_id', $tenantId)->count();
    expect($proxyCount)->toBeGreaterThanOrEqual(1);

    // 3. Check (audit) — should start an audit job.
    $auditResult = $bus->dispatch(new StartAuditCommand(
        tenantId: $tenantId,
        trigger: 'vertical_test',
    ));

    expect($auditResult->success)->toBeTrue();

    // 4. Export — should produce output.
    $exportResult = $bus->dispatch(new ExportInventoryCommand(
        tenantId: $tenantId,
        format: 'json',
        requestedBy: 'vertical_test',
    ));

    expect($exportResult->success)->toBeTrue();
    expect($exportResult->data)->not->toBeNull();

    // 5. Settings — read defaults.
    $settingsResult = $bus->query(new WorkspaceSettingsQuery(tenantId: $tenantId));
    expect($settingsResult->found)->toBeTrue();

    app(TenantContext::class)->forget();
})->skip(fn () => ! class_exists(User::class), 'App\\Models\\User not available');
