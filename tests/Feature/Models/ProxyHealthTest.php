<?php

declare(strict_types=1);

use App\Models\User;
use BAGArt\ProxyOperations\Models\ProxyAccess;
use BAGArt\ProxyOperations\Models\ProxyHealth;
use BAGArt\ProxyOperations\Tenancy\TenantContext;
use BAGArt\ProxyOperations\Tenancy\TenantNotResolvedException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

function healthTenantUser(): User
{
    return User::factory()->create();
}

function healthTenant(): int
{
    $userId = healthTenantUser()->id;
    app(TenantContext::class)->set($userId);

    return $userId;
}

it('creates the proxy_health table with expected columns and indexes', function (): void {
    expect(Schema::hasTable('proxy_health'))->toBeTrue()
        ->and(Schema::hasColumns('proxy_health', [
            'id', 'tenant_id', 'access_id', 'health_score', 'capability_score',
            'target_health', 'latency_percentiles', 'anonymity_tier',
            'anonymity_classifier_version', 'health_formula_version',
            'fresh_until', 'computed_at', 'created_at', 'updated_at',
        ]))->toBeTrue();

    $indexes = collect(DB::select("PRAGMA index_list('proxy_health')"));
    $uniqueIndex = $indexes->firstWhere(fn (object $index) => (bool) $index->unique);
    $freshIndex = $indexes->firstWhere(fn (object $index) => ! ((bool) $index->unique)
        && str_contains((string) $index->name, 'fresh_until'));

    expect($uniqueIndex)->not->toBeNull()
        ->and($freshIndex)->not->toBeNull();

    $uniqueColumns = collect(DB::select("PRAGMA index_info('{$uniqueIndex->name}')"))
        ->pluck('name')->all();

    expect($uniqueColumns)->toBe(['tenant_id', 'access_id']);
});

it('creates a minimal health row within tenant context with defaults', function (): void {
    $tenantId = healthTenant();

    $health = ProxyHealth::factory()->create();

    expect($health->tenant_id)->toBe($tenantId)
        ->and($health->access)->toBeInstanceOf(ProxyAccess::class)
        // IMPROVE#1: capability_score is strictly separate, both start NULL.
        ->and($health->health_score)->toBeNull()
        ->and($health->capability_score)->toBeNull()
        ->and($health->target_health)->toBeNull()
        ->and($health->latency_percentiles)->toBeNull()
        ->and($health->anonymity_tier)->toBeNull()
        ->and($health->anonymity_classifier_version)->toBeNull()
        // R6.6: the formula version is always present next to derived values.
        ->and($health->health_formula_version)->toBe('v1')
        ->and($health->fresh_until)->not->toBeNull()
        ->and($health->computed_at)->not->toBeNull();
});

it('roundtrips a fully populated healthy row with json payloads', function (): void {
    healthTenant();

    $health = ProxyHealth::factory()->healthy()->create();

    expect($health->health_score)->toBe(90)
        ->and($health->capability_score)->toBe(80)
        ->and($health->target_health)->toBe([
            ['target' => 'http://judge.example.internal', 'score' => 90],
        ])
        ->and($health->latency_percentiles)->toBe([
            'p50' => 120, 'p95' => 300, 'p99' => 500, 'jitter_ms' => 40,
        ])
        ->and($health->anonymity_tier)->toBe('anonymous')
        ->and($health->anonymity_classifier_version)->toBe('v1');
});

it('rejects a health row without an access — INV-001 access scoping is enforced by FK', function (): void {
    healthTenant();

    ProxyHealth::factory()->create(['access_id' => '00000000-0000-0000-0000-000000000000']);
})->throws(QueryException::class);

it('rejects a duplicate (tenant, access) health row', function (): void {
    healthTenant();

    $access = ProxyAccess::factory()->create();
    ProxyHealth::factory()->for($access, 'access')->create();

    expect(fn (): ProxyHealth => ProxyHealth::factory()->for($access, 'access')->create())
        ->toThrow(UniqueConstraintViolationException::class);
});

it('hides health rows of other tenants from queries', function (): void {
    $context = app(TenantContext::class);

    $context->set(healthTenant());
    $mine = ProxyHealth::factory()->create();

    $context->set(healthTenant());

    expect(ProxyHealth::query()->whereKey($mine->id)->exists())->toBeFalse()
        ->and(ProxyHealth::query()->count())->toBe(0);
});

it('throws on queries and creates without a resolved tenant instead of falling back to unscoped', function (): void {
    app(TenantContext::class)->forget();

    expect(fn () => ProxyHealth::query()->get())->toThrow(TenantNotResolvedException::class);

    app(TenantContext::class)->forget();

    expect(fn (): ProxyHealth => ProxyHealth::factory()->create())
        ->toThrow(TenantNotResolvedException::class);
});
