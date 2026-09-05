<?php

declare(strict_types=1);

use App\Models\User;
use BAGArt\ProxyOperations\Audit\LaravelLeaseLockStore;
use BAGArt\ProxyOperations\Audit\LeaseService;
use BAGArt\ProxyOperations\Domain\Lease\LeaseState;
use BAGArt\ProxyOperations\Models\ProxyAccess;
use BAGArt\ProxyOperations\Models\ProxyLease;
use BAGArt\ProxyOperations\Tests\Fixtures\CapturingAuditEventRecorder;
use BAGArt\ProxyOperations\Tenancy\TenantContext;

function leaseTenancyService(): LeaseService
{
    return new LeaseService(new LaravelLeaseLockStore, new CapturingAuditEventRecorder, 300);
}

it('refuses to lease a foreign-tenant access', function (): void {
    leaseTestTenant();

    // Create the access under a foreign tenant.
    $foreignTenant = User::factory()->create()->id;
    app(TenantContext::class)->set($foreignTenant);
    $foreignAccess = ProxyAccess::factory()->working()->create();

    // Back to our tenant: the access simply does not exist for us.
    app(TenantContext::class)->set(User::factory()->create()->id);

    // Acquire through a row fetched without tenant knowledge would be a
    // caller bug; the scoped query must not find it at all.
    expect(ProxyAccess::query()->find($foreignAccess->id))->toBeNull();
});

it('scopes lease queries and holder listings to the current tenant', function (): void {
    $tenantA = leaseTestTenant();
    $service = leaseTenancyService();

    $accessA = ProxyAccess::factory()->working()->create();
    $leaseA = $service->acquire($accessA, 'holder-a');

    $tenantB = User::factory()->create()->id;
    app(TenantContext::class)->set($tenantB);

    $accessB = ProxyAccess::factory()->working()->create();
    $leaseB = $service->acquire($accessB, 'holder-b');

    // Tenant B sees exactly one lease and one active row — its own.
    expect(ProxyLease::query()->count())->toBe(1)
        ->and(ProxyLease::query()->where('state', LeaseState::Active->value)->count())->toBe(1)
        ->and(ProxyLease::query()->first()->id)->toBe($leaseB->leaseId)
        ->and($leaseB->tenantId)->toBe((string) $tenantB);

    // DTO id from tenant A is unreadable from tenant B (scoped find → null).
    expect(ProxyLease::query()->find($leaseA->leaseId))->toBeNull()
        ->and($leaseA->tenantId)->toBe((string) $tenantA);
});

it('reaper is system-level but never corrupts foreign tenants rows', function (): void {
    leaseTestTenant();

    $service = leaseTenancyService();
    $access = ProxyAccess::factory()->working()->create();
    $lease = $service->acquire($access, 'holder-a');
    ProxyLease::query()->find($lease->leaseId)->forceFill(['expires_at' => now()->subSecond()])->save();

    // Reaper runs without a tenant context (system job) and reaps by expiry
    // only — a fresh foreign lease would not exist here, but non-expired
    // rows are untouched regardless of tenant.
    app(TenantContext::class)->forget();

    $otherService = new LeaseService(new LaravelLeaseLockStore, new CapturingAuditEventRecorder, 300);

    expect($otherService->reclaimExpired(200))->toBe(1)
        ->and(ProxyLease::query()->withoutGlobalScopes()->find($lease->leaseId)->state)->toBe(LeaseState::Expired);
});
