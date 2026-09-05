<?php

declare(strict_types=1);

use App\Models\User;
use BAGArt\ProxyOperations\Models\ProxySource;
use BAGArt\ProxyOperations\Models\SourceKind;
use BAGArt\ProxyOperations\Tenancy\TenantContext;
use BAGArt\ProxyOperations\Tenancy\TenantNotResolvedException;
use Illuminate\Support\Facades\DB;

function sourceTenantUser(): User
{
    return User::factory()->create();
}

function sourceTenant(): int
{
    $userId = sourceTenantUser()->id;
    app(TenantContext::class)->set($userId);

    return $userId;
}

it('creates the proxy_sources table with expected columns and indexes', function (): void {
    expect(Schema::hasTable('proxy_sources'))->toBeTrue()
        ->and(Schema::hasColumns('proxy_sources', [
            'id', 'tenant_id', 'kind', 'label', 'feed_id',
            'import_policy_version', 'enabled', 'last_synced_at',
            'created_at', 'updated_at',
        ]))->toBeTrue();

    $indexes = collect(DB::select("PRAGMA index_list('proxy_sources')"));
    $enabledIndex = $indexes->firstWhere(fn (object $index) => str_contains((string) $index->name, 'enabled'));

    expect($enabledIndex)->not->toBeNull();

    $enabledColumns = collect(DB::select("PRAGMA index_info('{$enabledIndex->name}')"))
        ->pluck('name')->all();

    expect($enabledColumns)->toBe(['tenant_id', 'enabled']);
});

it('creates a source within tenant context with column defaults', function (): void {
    $tenantId = sourceTenant();

    $source = ProxySource::factory()->create();

    expect($source->tenant_id)->toBe($tenantId)
        ->and($source->kind)->toBeInstanceOf(SourceKind::class)
        ->and($source->kind)->toBe(SourceKind::Paste)
        ->and($source->enabled)->toBeTrue()
        ->and($source->feed_id)->toBeNull()
        ->and($source->import_policy_version)->toBeNull()
        ->and($source->last_synced_at)->toBeNull();
});

it('roundtrips a feed source with its import policy version', function (): void {
    sourceTenant();

    $feed = ProxySource::factory()->feed()->create(['label' => 'hourly list']);

    expect($feed->kind)->toBe(SourceKind::Feed)
        ->and($feed->feed_id)->not->toBeNull()
        ->and($feed->import_policy_version)->toBe('v1');

    $feed->refresh();

    expect($feed->label)->toBe('hourly list');
});

it('hides sources of other tenants from queries', function (): void {
    $context = app(TenantContext::class);

    $context->set(sourceTenant());
    $mine = ProxySource::factory()->create();

    $context->set(sourceTenant());

    expect(ProxySource::query()->whereKey($mine->id)->exists())->toBeFalse()
        ->and(ProxySource::query()->count())->toBe(0);
});

it('throws on queries and creates without a resolved tenant instead of falling back to unscoped', function (): void {
    app(TenantContext::class)->forget();

    expect(fn () => ProxySource::query()->get())->toThrow(TenantNotResolvedException::class);

    app(TenantContext::class)->forget();

    expect(fn (): ProxySource => ProxySource::factory()->create())
        ->toThrow(TenantNotResolvedException::class);
});
