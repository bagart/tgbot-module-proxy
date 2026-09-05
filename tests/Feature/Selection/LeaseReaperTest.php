<?php

declare(strict_types=1);

use App\Models\User;
use BAGArt\ProxyOperations\Audit\LaravelLeaseLockStore;
use BAGArt\ProxyOperations\Audit\LeaseReaperCommand;
use BAGArt\ProxyOperations\Audit\LeaseService;
use BAGArt\ProxyOperations\Domain\Lease\LeaseState;
use BAGArt\ProxyOperations\Models\ProxyAccess;
use BAGArt\ProxyOperations\Models\ProxyLease;
use BAGArt\ProxyOperations\Tests\Fixtures\CapturingAuditEventRecorder;
use BAGArt\ProxyOperations\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;

function reapTenant(): int
{
    $userId = User::factory()->create()->id;
    app(TenantContext::class)->set($userId);

    return $userId;
}

function reapService(): LeaseService
{
    return new LeaseService(new LaravelLeaseLockStore, new CapturingAuditEventRecorder, 300);
}

it('recovers a crashed consumer: expired lease is reaped and access re-acquirable', function (): void {
    reapTenant();

    $access = ProxyAccess::factory()->working()->create();
    $service = reapService();

    // The consumer acquires, then "dies" before release/renew.
    $lease = $service->acquire($access, 'consumer-crashed');

    // TTL passes with nobody renewing.
    ProxyLease::query()->find($lease->leaseId)->forceFill(['expires_at' => now()->subSecond()])->save();

    // Recovery: one reaper pass.
    $this->artisan(LeaseReaperCommand::class)->assertSuccessful();

    $row = ProxyLease::query()->find($lease->leaseId);
    expect($row->state)->toBe(LeaseState::Expired)
        ->and($row->active_marker)->toBeNull()
        ->and(Cache::has('proxy:lease:lock:'.$access->id))->toBeFalse();

    // A new holder acquires the same access.
    $next = reapService()->acquire($access, 'consumer-new');
    expect($next)->not->toBeNull();
});

it('recovers even when Redis is flushed entirely between crash and reap', function (): void {
    reapTenant();

    $access = ProxyAccess::factory()->working()->create();
    $service = reapService();

    $lease = $service->acquire($access, 'consumer-crashed');
    ProxyLease::query()->find($lease->leaseId)->forceFill(['expires_at' => now()->subSecond()])->save();

    Cache::flush(); // total Redis loss

    $recovered = reapService();
    expect($recovered->reclaimExpired(200))->toBe(1);

    $next = $recovered->acquire($access, 'consumer-new');
    expect($next)->not->toBeNull();
});

it('never touches fresh or released leases', function (): void {
    reapTenant();

    $service = reapService();

    $freshAccess = ProxyAccess::factory()->working()->create();
    $fresh = $service->acquire($freshAccess, 'holder-fresh');

    $releasedAccess = ProxyAccess::factory()->working()->create();
    $released = $service->acquire($releasedAccess, 'holder-released');
    $service->release($released);

    $expiredAccess = ProxyAccess::factory()->working()->create();
    $expired = $service->acquire($expiredAccess, 'holder-expired');
    ProxyLease::query()->find($expired->leaseId)->forceFill(['expires_at' => now()->subSecond()])->save();

    expect($service->reclaimExpired(200))->toBe(1)
        ->and(ProxyLease::query()->find($fresh->leaseId)->state)->toBe(LeaseState::Active)
        ->and(ProxyLease::query()->find($released->leaseId)->state)->toBe(LeaseState::Released)
        ->and(ProxyLease::query()->find($expired->leaseId)->state)->toBe(LeaseState::Expired);
});
