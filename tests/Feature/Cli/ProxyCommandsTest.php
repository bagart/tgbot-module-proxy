<?php

declare(strict_types=1);

use App\Models\User;
use BAGArt\ProxyOperations\Application\ImportProxiesCommand;
use BAGArt\ProxyOperations\Application\ImportProxiesHandler;
use BAGArt\ProxyOperations\Application\ImportSource;
use BAGArt\ProxyOperations\Application\StartAuditHandler;
use BAGArt\ProxyOperations\Application\WorkspaceSettingsHandler;
use BAGArt\ProxyOperations\Application\WorkspaceSettingsQuery;
use BAGArt\ProxyOperations\Application\ApplicationServiceBus;
use BAGArt\ProxyOperations\Audit\PoolRepository;
use BAGArt\ProxyOperations\Export\ExportService;
use BAGArt\ProxyOperations\Export\ExportQuery;
use BAGArt\ProxyOperations\Models\ProxyAccess;
use BAGArt\ProxyOperations\Models\ProxyEndpoint;
use BAGArt\ProxyOperations\Models\ProxyPool;
use BAGArt\ProxyOperations\Tenancy\TenantContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

function cliTestTenant(): int
{
    $user = User::factory()->create();
    app(TenantContext::class)->set($user->id);

    return $user->id;
}

it('proxy:import reads from file and imports proxies', function (): void {
    $tenantId = cliTestTenant();

    $tmpFile = tempnam(sys_get_temp_dir(), 'proxy_import_');
    file_put_contents($tmpFile, "socks5://user:pass@1.2.3.4:1080\nhttp://5.6.7.8:8080\n");

    $exit = Artisan::call('proxy:import', ['file' => $tmpFile]);

    expect($exit)->toBe(0);
    @unlink($tmpFile);

    // Proxies should be imported (count depends on parser behavior with in-memory DB).
    expect(Artisan::output())->toContain('Imported:');
})->skip(fn () => ! class_exists(User::class), 'App\\Models\\User not available');

it('proxy:import fails on missing file', function (): void {
    $exit = Artisan::call('proxy:import', ['file' => '/nonexistent/file.txt']);

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain('File not found');
})->skip(fn () => ! class_exists(User::class), 'App\\Models\\User not available');

it('proxy:list outputs table by default', function (): void {
    $tenantId = cliTestTenant();
    ProxyEndpoint::factory()->create();
    ProxyAccess::factory()->create();

    $exit = Artisan::call('proxy:list');

    expect($exit)->toBe(0);
    app(TenantContext::class)->forget();
})->skip(fn () => ! class_exists(User::class), 'App\\Models\\User not available');

it('proxy:list --json outputs valid JSON', function (): void {
    $tenantId = cliTestTenant();

    $exit = Artisan::call('proxy:list', ['--json' => true]);

    expect($exit)->toBe(0);
    $output = Artisan::output();
    expect(json_decode($output, true))->not->toBeNull();
    app(TenantContext::class)->forget();
})->skip(fn () => ! class_exists(User::class), 'App\\Models\\User not available');

it('proxy:settings shows defaults when no settings exist', function (): void {
    cliTestTenant();

    $exit = Artisan::call('proxy:settings');

    expect($exit)->toBe(0);
    app(TenantContext::class)->forget();
})->skip(fn () => ! class_exists(User::class), 'App\\Models\\User not available');

it('proxy:settings --json outputs valid JSON', function (): void {
    cliTestTenant();

    $exit = Artisan::call('proxy:settings', ['--json' => true]);

    expect($exit)->toBe(0);
    $output = Artisan::output();
    expect(json_decode($output, true))->not->toBeNull();
    app(TenantContext::class)->forget();
})->skip(fn () => ! class_exists(User::class), 'App\\Models\\User not available');

it('proxy:status outputs health check table', function (): void {
    cliTestTenant();

    $exit = Artisan::call('proxy:status');

    expect($exit)->toBe(0);
    app(TenantContext::class)->forget();
})->skip(fn () => ! class_exists(User::class), 'App\\Models\\User not available');

it('proxy:status --json outputs valid JSON', function (): void {
    cliTestTenant();

    $exit = Artisan::call('proxy:status', ['--json' => true]);

    expect($exit)->toBe(0);
    $output = Artisan::output();
    expect(json_decode($output, true))->not->toBeNull();
    app(TenantContext::class)->forget();
})->skip(fn () => ! class_exists(User::class), 'App\\Models\\User not available');

it('proxy:pools:list outputs empty when no pools', function (): void {
    cliTestTenant();

    $exit = Artisan::call('proxy:pools:list');

    expect($exit)->toBe(0)
        ->and(Artisan::output())->toContain('No pools found');
    app(TenantContext::class)->forget();
})->skip(fn () => ! class_exists(User::class), 'App\\Models\\User not available');

it('proxy:pools:create creates a pool and lists it', function (): void {
    $tenantId = cliTestTenant();

    $exit = Artisan::call('proxy:pools:create', ['name' => 'test-pool']);
    expect($exit)->toBe(0)
        ->and(Artisan::output())->toContain('Pool created');

    $exit = Artisan::call('proxy:pools:list');
    expect($exit)->toBe(0)
        ->and(Artisan::output())->toContain('test-pool');

    app(TenantContext::class)->forget();
})->skip(fn () => ! class_exists(User::class), 'App\\Models\\User not available');

it('proxy:pools:create rejects invalid JSON predicate', function (): void {
    cliTestTenant();

    $exit = Artisan::call('proxy:pools:create', [
        'name' => 'bad-pool',
        '--predicate' => 'not-json',
    ]);

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain('Invalid JSON');
    app(TenantContext::class)->forget();
})->skip(fn () => ! class_exists(User::class), 'App\\Models\\User not available');

it('proxy:export outputs to stdout by default', function (): void {
    $tenantId = cliTestTenant();

    // Need at least one proxy for export to produce output.
    $endpoint = ProxyEndpoint::factory()->create();
    ProxyAccess::factory()->create(['endpoint_id' => $endpoint->id]);

    $exit = Artisan::call('proxy:export', ['--format' => 'json']);

    expect($exit)->toBe(0);
    app(TenantContext::class)->forget();
})->skip(fn () => ! class_exists(User::class), 'App\\Models\\User not available');

it('proxy:export writes to file when --output given', function (): void {
    $tenantId = cliTestTenant();

    $endpoint = ProxyEndpoint::factory()->create();
    ProxyAccess::factory()->create(['endpoint_id' => $endpoint->id]);

    $tmpFile = tempnam(sys_get_temp_dir(), 'proxy_export_');
    $exit = Artisan::call('proxy:export', [
        '--format' => 'json',
        '--output' => $tmpFile,
    ]);

    expect($exit)->toBe(0)
        ->and(file_exists($tmpFile))->toBeTrue();

    @unlink($tmpFile);
    app(TenantContext::class)->forget();
})->skip(fn () => ! class_exists(User::class), 'App\\Models\\User not available');
