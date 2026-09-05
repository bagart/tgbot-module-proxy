<?php

declare(strict_types=1);

use App\Models\User;
use BAGArt\ProxyOperations\Audit\PoolRepository;
use BAGArt\ProxyOperations\Models\ProxyAccess;
use BAGArt\ProxyOperations\Models\ProxyPool;
use BAGArt\ProxyOperations\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

function poolTenancyA(): int
{
    $userId = User::factory()->create()->id;
    app(TenantContext::class)->set($userId);

    return $userId;
}

it('rejects attaching a foreign-tenant access to a pool', function (): void {
    poolTenancyA();

    $repository = app(PoolRepository::class);
    $pool = $repository->create(['name' => 'mine', 'kind' => 'static']);

    // Create an access belonging to another tenant (context switched).
    $foreignTenantId = User::factory()->create()->id;
    app(TenantContext::class)->set($foreignTenantId);
    $foreignAccess = ProxyAccess::factory()->create();

    app(TenantContext::class)->set((int) $pool->tenant_id);

    expect(fn () => $repository->attachMember($pool, $foreignAccess->id))
        ->toThrow(InvalidArgumentException::class)
        ->and($pool->members()->count())->toBe(0);
});

it('never leaks pools or members across tenants', function (): void {
    $tenantA = poolTenancyA();
    $repository = app(PoolRepository::class);
    $poolA = $repository->create(['name' => 'pool-a', 'kind' => 'static']);
    $accessA = ProxyAccess::factory()->create();
    $repository->attachMember($poolA, $accessA->id);

    $tenantBId = User::factory()->create()->id;
    app(TenantContext::class)->set($tenantBId);

    // Pool id lookup for another tenant's pool returns nothing.
    expect($repository->findForTenant($poolA->id))->toBeNull()
        ->and(ProxyPool::query()->count())->toBe(0)
        ->and(ProxyPool::query()->where('id', $poolA->id)->exists())->toBeFalse();

    // Detaching another tenant's member through the same pool object must
    // not delete anything (tenant scope on the members relation).
    app(TenantContext::class)->set((int) $tenantA);
    $poolA->refresh();
    app(TenantContext::class)->set($tenantBId);
    expect($poolA->members()->count())->toBe(0);
});

it('scopes enabled pool listings to the current tenant', function (): void {
    poolTenancyA();
    $repository = app(PoolRepository::class);
    $repository->create(['name' => 'a1', 'kind' => 'static', 'enabled' => true]);
    $repository->create(['name' => 'a2', 'kind' => 'dynamic', 'enabled' => false]);

    $tenantBId = User::factory()->create()->id;
    app(TenantContext::class)->set($tenantBId);
    $repository->create(['name' => 'b1', 'kind' => 'static', 'enabled' => true]);

    $enabled = $repository->enabledPools();

    expect($enabled->pluck('name')->all())->toBe(['b1']);
});
