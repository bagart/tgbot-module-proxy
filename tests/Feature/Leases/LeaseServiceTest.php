<?php

declare(strict_types=1);

use App\Models\User;
use BAGArt\ProxyOperations\Audit\LaravelLeaseLockStore;
use BAGArt\ProxyOperations\Audit\LeaseLockStore;
use BAGArt\ProxyOperations\Audit\LeaseService;
use BAGArt\ProxyOperations\Domain\Lease\LeaseState;
use BAGArt\ProxyOperations\Models\ProxyAccess;
use BAGArt\ProxyOperations\Models\ProxyLease;
use BAGArt\ProxyOperations\Tests\Fixtures\CapturingAuditEventRecorder;
use BAGArt\ProxyOperations\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;

function leaseTestTenant(): int
{
    $userId = User::factory()->create()->id;
    app(TenantContext::class)->set($userId);

    return $userId;
}

function leaseTestService(?LeaseLockStore $locks = null): LeaseService
{
    return new LeaseService(
        locks: $locks ?? new LaravelLeaseLockStore,
        events: new CapturingAuditEventRecorder,
        ttlSeconds: 300,
    );
}

/**
 * LeaseLockStore fake with an injectable failure mode for Redis-outage tests.
 */
final class FailingLeaseLockStore implements LeaseLockStore
{
    public function __construct(private readonly bool $failAcquire = false, private readonly bool $failRenew = false, private readonly bool $failRelease = false) {}

    public function acquire(string $lockKey, string $holder, int $ttlMs): bool
    {
        return ! $this->failAcquire;
    }

    public function renew(string $lockKey, string $holder, int $ttlMs): bool
    {
        return ! $this->failRenew;
    }

    public function release(string $lockKey, string $holder): void
    {
        if ($this->failRelease) {
            throw new RuntimeException('store down');
        }
    }
}

it('creates the proxy_leases table with expected columns and constraints', function (): void {
    leaseTestTenant();

    $lease = ProxyLease::factory()->create();

    expect($lease->state)->toBe(LeaseState::Active)
        ->and($lease->active_marker)->toBe(1)
        ->and($lease->renewals)->toBe(0)
        ->and($lease->purpose)->toBe('session');
});

it('acquires an active lease and rejects a second acquire for the same access', function (): void {
    leaseTestTenant();

    $access = ProxyAccess::factory()->working()->create();
    $service = leaseTestService();

    $lease = $service->acquire($access, 'holder-1');

    expect($lease)->not->toBeNull()
        ->and($lease->accessId)->toBe($access->id)
        ->and($lease->holder)->toBe('holder-1')
        ->and($lease->ttlMs(time() * 1000))->toBeGreaterThan(290_000);

    expect($service->acquire($access, 'holder-2'))->toBeNull();

    // Exactly one active row; no leaked lock from the rejected acquire.
    expect(ProxyLease::query()->where('state', LeaseState::Active->value)->count())->toBe(1)
        ->and(Cache::has('proxy:lease:lock:'.$access->id))->toBeTrue();
});

it('renews by the holder and refuses expired or foreign renewals', function (): void {
    leaseTestTenant();

    $access = ProxyAccess::factory()->working()->create();
    $service = leaseTestService();

    $lease = $service->acquire($access, 'holder-1');

    $renewed = $service->renew($lease);
    expect($renewed)->not->toBeNull()
        ->and($renewed->expiresAtMs >= $lease->expiresAtMs)->toBeTrue();

    expect(ProxyLease::query()->find($lease->leaseId)->renewals)->toBe(1);

    // Foreign holder.
    $foreign = $lease;
    expect($service->renew(new BAGArt\ProxyOperations\Domain\Lease\ProxyLeaseDto(
        leaseId: $foreign->leaseId,
        accessId: $foreign->accessId,
        tenantId: $foreign->tenantId,
        holder: 'someone-else',
        purpose: $foreign->purpose,
        acquiredAtMs: $foreign->acquiredAtMs,
        expiresAtMs: $foreign->expiresAtMs,
    )))->toBeNull();

    // Expired lease.
    $row = ProxyLease::query()->find($lease->leaseId);
    $row->forceFill(['expires_at' => now()->subSecond()])->save();

    expect($service->renew($lease))->toBeNull();
});

it('releases and frees the access for a new lease', function (): void {
    leaseTestTenant();

    $access = ProxyAccess::factory()->working()->create();
    $service = leaseTestService();

    $lease = $service->acquire($access, 'holder-1');
    $service->release($lease);

    $row = ProxyLease::query()->find($lease->leaseId);
    expect($row->state)->toBe(LeaseState::Released)
        ->and($row->active_marker)->toBeNull()
        ->and($row->released_at)->not->toBeNull()
        ->and(Cache::has('proxy:lease:lock:'.$access->id))->toBeFalse();

    $next = $service->acquire($access, 'holder-2');
    expect($next)->not->toBeNull()
        ->and($next->holder)->toBe('holder-2');
});

it('reaps expired leases and returns the access to the pool', function (): void {
    leaseTestTenant();

    $access = ProxyAccess::factory()->working()->create();
    $service = leaseTestService();

    $lease = $service->acquire($access, 'holder-1');

    // Simulate a crashed consumer: nobody renewed, TTL passed.
    ProxyLease::query()->find($lease->leaseId)->forceFill(['expires_at' => now()->subSecond()])->save();

    // Fresh lease of another access stays untouched.
    $freshAccess = ProxyAccess::factory()->working()->create();
    $fresh = $service->acquire($freshAccess, 'holder-2');

    $reaped = $service->reclaimExpired(200);

    expect($reaped)->toBe(1);

    $row = ProxyLease::query()->find($lease->leaseId);
    expect($row->state)->toBe(LeaseState::Expired)
        ->and($row->active_marker)->toBeNull()
        ->and(Cache::has('proxy:lease:lock:'.$access->id))->toBeFalse()
        ->and(ProxyLease::query()->find($fresh->leaseId)->state)->toBe(LeaseState::Active);

    // Access acquirable again by a new holder.
    $next = $service->acquire($access, 'holder-3');
    expect($next)->not->toBeNull();
});

it('fails closed on Redis loss: no lock means no lease, release still lands', function (): void {
    leaseTestTenant();

    $access = ProxyAccess::factory()->working()->create();

    // Store down at acquire time → null and NO Postgres row.
    $broken = new LeaseService(new FailingLeaseLockStore(failAcquire: true), new CapturingAuditEventRecorder, 300);
    expect($broken->acquire($access, 'holder-1'))->toBeNull()
        ->and(ProxyLease::query()->count())->toBe(0);

    // Store down at renew time → no extension.
    $working = leaseTestService();
    $lease = $working->acquire($access, 'holder-1');
    $renewStore = new LeaseService(new FailingLeaseLockStore(failRenew: true), new CapturingAuditEventRecorder, 300);
    expect($renewStore->renew($lease))->toBeNull();

    // Store down at release time → Postgres state still released.
    $releaseStore = new LeaseService(new FailingLeaseLockStore(failRelease: true), new CapturingAuditEventRecorder, 300);
    $releaseStore->release($lease);

    expect(ProxyLease::query()->find($lease->leaseId)->state)->toBe(LeaseState::Released);
});

it('records lease events after commit, none on failed acquire', function (): void {
    leaseTestTenant();

    $recorder = new CapturingAuditEventRecorder;
    $access = ProxyAccess::factory()->working()->create();
    $service = new LeaseService(new LaravelLeaseLockStore, $recorder, 300);

    $lease = $service->acquire($access, 'holder-1');
    $service->acquire($access, 'holder-2'); // rejected
    $service->release($lease);

    $types = array_map(fn ($r) => $r['envelope']->eventType, $recorder->records);

    expect($types)->toBe(['lease.acquired', 'lease.released'])
        ->and($recorder->records[0]['envelope']->aggregateRef)->toBe($lease->leaseId)
        ->and($recorder->records[0]['envelope']->payload['accessId'])->toBe($access->id)
        ->and($recorder->records[0]['envelope']->payload)->not->toContain('password');
});
