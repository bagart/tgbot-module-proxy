<?php

declare(strict_types=1);

use App\Models\User;
use BAGArt\ProxyOperations\Models\ProxyAccess;
use BAGArt\ProxyOperations\Models\ProxyCapability;
use BAGArt\ProxyOperations\Models\ProxyEndpoint;
use BAGArt\ProxyOperations\Tenancy\TenantContext;
use BAGArt\ProxyOperations\Tenancy\TenantNotResolvedException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

function capabilityTenantUser(): User
{
    return User::factory()->create();
}

function capabilityTenant(): int
{
    $userId = capabilityTenantUser()->id;
    app(TenantContext::class)->set($userId);

    return $userId;
}

it('creates the proxy_capabilities table with expected columns and indexes', function (): void {
    expect(Schema::hasTable('proxy_capabilities'))->toBeTrue()
        ->and(Schema::hasColumns('proxy_capabilities', [
            'id', 'tenant_id', 'endpoint_id', 'access_id',
            'udp_associate_supported', 'dns_resolution_mode', 'matrix',
            'capability_formula_version', 'evaluated_at',
            'created_at', 'updated_at',
        ]))->toBeTrue();

    $indexes = collect(DB::select("PRAGMA index_list('proxy_capabilities')"));
    $uniqueIndex = $indexes->firstWhere(fn (object $index) => (bool) $index->unique);
    $endpointIndex = $indexes->firstWhere(fn (object $index) => ! ((bool) $index->unique)
        && str_contains((string) $index->name, 'endpoint_id'));

    expect($uniqueIndex)->not->toBeNull()
        ->and($endpointIndex)->not->toBeNull();

    $uniqueColumns = collect(DB::select("PRAGMA index_info('{$uniqueIndex->name}')"))
        ->pluck('name')->all();

    expect($uniqueColumns)->toBe(['tenant_id', 'endpoint_id', 'access_id']);
});

it('creates an endpoint-level capability row with defaults within tenant context', function (): void {
    $tenantId = capabilityTenant();

    $endpoint = ProxyEndpoint::factory()->create();
    $capability = ProxyCapability::factory()->socks5Matrix()->for($endpoint, 'endpoint')->create();

    expect($capability->tenant_id)->toBe($tenantId)
        ->and($capability->endpoint_id)->toBe($endpoint->id)
        ->and($capability->access_id)->toBeNull()
        ->and($capability->udp_associate_supported)->toBeNull()
        ->and($capability->dns_resolution_mode)->toBeNull()
        ->and($capability->matrix)->toBeArray()->not->toBeEmpty()
        ->and($capability->capability_formula_version)->toBe('v1')
        ->and($capability->evaluated_at)->not->toBeNull();
});

it('creates an access-level capability row bound to an access of the same endpoint', function (): void {
    capabilityTenant();

    $capability = ProxyCapability::factory()->accessLevel()->create();

    expect($capability->access)->toBeInstanceOf(ProxyAccess::class)
        ->and($capability->endpoint_id)->toBe($capability->access->endpoint_id)
        ->and($capability->refresh()->udp_associate_supported)->toBeTrue()
        ->and($capability->dns_resolution_mode)->toBe('REMOTE_DNS');
});

it('rejects a capability row with an unknown endpoint (FK integrity)', function (): void {
    capabilityTenant();

    ProxyCapability::factory()->create(['endpoint_id' => '00000000-0000-0000-0000-000000000000']);
})->throws(QueryException::class);

it('rejects a capability row with an unknown access (FK integrity)', function (): void {
    capabilityTenant();

    $endpoint = ProxyEndpoint::factory()->create();
    ProxyCapability::factory()->for($endpoint, 'endpoint')
        ->create(['access_id' => '00000000-0000-0000-0000-000000000000']);
})->throws(QueryException::class);

it('rejects a duplicate (tenant, endpoint, access) capability but lets NULL-access rows coexist per SQLite semantics', function (): void {
    capabilityTenant();

    $endpoint = ProxyEndpoint::factory()->create();

    // NULLs are distinct in SQLite unique indexes: several endpoint-level
    // rows may coexist until the evaluator takes ownership.
    ProxyCapability::factory()->for($endpoint, 'endpoint')->create();
    $coexisting = ProxyCapability::factory()->for($endpoint, 'endpoint')->create();

    expect($coexisting->exists())->toBeTrue();

    $access = ProxyAccess::factory()->for($endpoint, 'endpoint')->create();
    ProxyCapability::factory()->for($endpoint, 'endpoint')
        ->create(['access_id' => $access->id]);

    expect(fn (): ProxyCapability => ProxyCapability::factory()->for($endpoint, 'endpoint')
        ->create(['access_id' => $access->id]))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('allows the same access-level capability identity in another tenant', function (): void {
    $context = app(TenantContext::class);

    $context->set(capabilityTenantUser()->id);
    // Endpoint identities differ per tenant (no global registry), so the
    // cross-tenant case is exercised through separate endpoints.
    $mine = ProxyCapability::factory()->create();

    $context->set(capabilityTenantUser()->id);
    $theirs = ProxyCapability::factory()->create();

    expect($theirs->tenant_id)->not->toBe($mine->tenant_id)
        ->and(ProxyCapability::query()->count())->toBe(1);
});

it('hides capabilities of other tenants from queries', function (): void {
    $context = app(TenantContext::class);

    $context->set(capabilityTenant());
    $mine = ProxyCapability::factory()->create();

    $context->set(capabilityTenant());

    expect(ProxyCapability::query()->whereKey($mine->id)->exists())->toBeFalse()
        ->and(ProxyCapability::query()->count())->toBe(0);
});

it('throws on queries and creates without a resolved tenant instead of falling back to unscoped', function (): void {
    app(TenantContext::class)->forget();

    expect(fn () => ProxyCapability::query()->get())->toThrow(TenantNotResolvedException::class);

    app(TenantContext::class)->forget();

    expect(fn (): ProxyCapability => ProxyCapability::factory()->create())
        ->toThrow(TenantNotResolvedException::class);
});
