<?php

declare(strict_types=1);

use App\Models\User;
use BAGArt\ProxyOperations\Audit\JobStarter;
use BAGArt\ProxyOperations\Audit\LaravelLeaseLockStore;
use BAGArt\ProxyOperations\Audit\LeaseService;
use BAGArt\ProxyOperations\Audit\PolicySnapshotBuilder;
use BAGArt\ProxyOperations\Audit\ProxySelector;
use BAGArt\ProxyOperations\Audit\CacheJobPlacementDedup;
use BAGArt\ProxyOperations\Domain\Evidence\FreshnessAwareEligibilityPolicy;
use BAGArt\ProxyOperations\Domain\Lease\LeastUsedSelectionStrategy;
use BAGArt\ProxyOperations\Domain\Lease\ProxyLeaseDto;
use BAGArt\ProxyOperations\Domain\Lease\RandomSelectionStrategy;
use BAGArt\ProxyOperations\Domain\Lease\RoundRobinSelectionStrategy;
use BAGArt\ProxyOperations\Domain\Lease\SelectionCriteria;
use BAGArt\ProxyOperations\Domain\Lease\WeightedSelectionStrategy;
use BAGArt\ProxyOperations\Domain\Pool\SelectionReasonCode;
use BAGArt\ProxyOperations\Models\ProxyAccess;
use BAGArt\ProxyOperations\Models\ProxyAuditJob;
use BAGArt\ProxyOperations\Models\ProxyEndpoint;
use BAGArt\ProxyOperations\Models\ProxyHealth;
use BAGArt\ProxyOperations\Models\ProxyLease;
use BAGArt\ProxyOperations\Models\ProxyPool;
use BAGArt\ProxyOperations\Models\ProxyPoolDecision;
use BAGArt\ProxyOperations\Models\ProxyPoolMember;
use BAGArt\ProxyOperations\Tests\Fixtures\CapturingAuditEventRecorder;
use BAGArt\ProxyOperations\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;

function selTenant(): int
{
    $userId = User::factory()->create()->id;
    app(TenantContext::class)->set($userId);

    return $userId;
}

function selEligibleWorkingAccess(): ProxyAccess
{
    $access = ProxyAccess::factory()->working()->create();

    ProxyHealth::query()->updateOrCreate(
        ['access_id' => $access->id],
        [
            'health_score' => 90,
            'dimension_signals' => ['tcp' => 'pass', 'http' => 'pass', 'judge' => 'pass'],
            'health_formula_version' => 'v1',
            'computed_at' => now(),
        ],
    );

    return $access;
}

function selStaleAccess(): ProxyAccess
{
    $access = ProxyAccess::factory()->working()->create();

    ProxyHealth::query()->updateOrCreate(
        ['access_id' => $access->id],
        [
            'health_score' => 90,
            'dimension_signals' => ['tcp' => 'pass', 'http' => 'pass', 'judge' => 'pass'],
            'health_formula_version' => 'v1',
            'computed_at' => now()->subHours(6),
        ],
    );

    return $access;
}

function selJobStarter(): JobStarter
{
    return new JobStarter(
        snapshots: new PolicySnapshotBuilder,
        placement: new CacheJobPlacementDedup,
        tenant: app(TenantContext::class),
        placementTtlSeconds: 300,
    );
}

function selSelector(?object $strategy = null): ProxySelector
{
    return new ProxySelector(
        strategy: $strategy ?? new RoundRobinSelectionStrategy('test'),
        leases: new LeaseService(new LaravelLeaseLockStore, new CapturingAuditEventRecorder, 300),
        eligibility: new FreshnessAwareEligibilityPolicy,
        jobStarter: selJobStarter(),
    );
}

function selPoolWith(array $accesses): ProxyPool
{
    $pool = ProxyPool::factory()->dynamic()->create();

    foreach ($accesses as $access) {
        ProxyPoolMember::query()->create([
            'pool_id' => $pool->id,
            'access_id' => $access->id,
            'added_at' => now(),
            'materialization_version' => 1,
        ]);
    }

    return $pool;
}

it('selects and leases the requested count from a healthy pool', function (): void {
    selTenant();

    $a = selEligibleWorkingAccess();
    $b = selEligibleWorkingAccess();
    $c = selEligibleWorkingAccess();
    $pool = selPoolWith([$a, $b, $c]);

    $leases = selSelector()->acquire((string) $pool->tenant_id, new SelectionCriteria(
        holder: 'consumer-1',
        poolId: $pool->id,
        count: 2,
    ));

    expect(count($leases))->toBe(2)
        ->and($leases[0])->toBeInstanceOf(ProxyLeaseDto::class);

    $selected = ProxyPoolDecision::query()->where('decision', 'selected')->count();
    $skipped = ProxyPoolDecision::query()->where('decision', 'skipped')->count();

    expect($selected)->toBe(2)
        ->and($skipped)->toBe(1); // third candidate ordered beyond count
});

it('logs a specific reason code per hard filter dimension', function (): void {
    selTenant();

    $excluded = selEligibleWorkingAccess();
    $wrongProtocol = selEligibleWorkingAccess();
    ProxyEndpoint::query()->where('id', $wrongProtocol->endpoint_id)->update(['protocol' => 'http']);
    $noTelegram = ProxyAccess::factory()->create(); // never checked
    ProxyHealth::query()->updateOrCreate(
        ['access_id' => $noTelegram->id],
        ['health_score' => 90, 'health_formula_version' => 'v1', 'computed_at' => now()],
    );

    $pool = selPoolWith([$excluded, $wrongProtocol, $noTelegram]);

    $leases = selSelector()->acquire((string) $pool->tenant_id, new SelectionCriteria(
        holder: 'consumer-1',
        poolId: $pool->id,
        requiredProtocol: 'socks5',
        telegramUsableOnly: true,
        excludeAccessIds: [$excluded->id],
    ));

    expect($leases)->toBe([]);

    $reasons = ProxyPoolDecision::query()->pluck('reason_code', 'access_id');
    expect($reasons[$excluded->id])->toBe(SelectionReasonCode::Excluded->value)
        ->and($reasons[$wrongProtocol->id])->toBe(SelectionReasonCode::PredicateProtocolMismatch->value)
        ->and($reasons[$noTelegram->id])->toBe(SelectionReasonCode::PredicateTelegramFilter->value);
});

it('skips stale candidates and starts a lazy-check job for each', function (): void {
    selTenant();

    $fresh = selEligibleWorkingAccess();
    $stale = selStaleAccess();
    $pool = selPoolWith([$fresh, $stale]);

    $leases = selSelector()->acquire((string) $pool->tenant_id, new SelectionCriteria(
        holder: 'consumer-1',
        poolId: $pool->id,
        maxStalenessSeconds: 3600,
    ));

    expect(count($leases))->toBe(1)
        ->and($leases[0]->accessId)->toBe($fresh->id);

    $job = ProxyAuditJob::query()->where('trigger', 'lazy_selection')->first();
    expect($job)->not->toBeNull()
        ->and($job->policySnapshot()->first())->not->toBeNull();

    expect(ProxyPoolDecision::query()->where('access_id', $stale->id)->first()->reason_code)
        ->toBe(SelectionReasonCode::StaleHealth->value);
});

it('falls back to the next candidate when a lease cannot be acquired', function (): void {
    selTenant();

    $first = selEligibleWorkingAccess();
    $second = selEligibleWorkingAccess();
    $pool = selPoolWith([$first, $second]);

    // Pre-lease the weighted-top candidate so its lease attempt fails.
    Cache::put('proxy:selection:test', 0, now()->addMinute()); // round robin at 0
    $selector = selSelector();
    $leases = $selector->acquire((string) $pool->tenant_id, new SelectionCriteria(
        holder: 'consumer-1',
        poolId: $pool->id,
        count: 2,
    ));

    expect(count($leases))->toBe(2);

    // Second run: both are leased (round robin cursor advanced) — the
    // selector must return fewer leases, not fail.
    $leases2 = selSelector()->acquire((string) $pool->tenant_id, new SelectionCriteria(
        holder: 'consumer-2',
        poolId: $pool->id,
        count: 2,
    ));

    expect($leases2)->toBe([])
        ->and(ProxyPoolDecision::query()->where('access_id', $first->id)->where('decision', 'skipped')->first()->reason_code)
        ->toBe(SelectionReasonCode::LeaseUnavailable->value);
});

it('returns an empty result for an unknown or foreign pool without touching leases', function (): void {
    selTenant();
    $access = selEligibleWorkingAccess();
    $pool = selPoolWith([$access]);

    // Foreign tenant owns nothing with this pool id.
    $foreignTenant = User::factory()->create()->id;
    app(TenantContext::class)->set($foreignTenant);

    $leases = selSelector()->acquire((string) $foreignTenant, new SelectionCriteria(
        holder: 'consumer-x',
        poolId: $pool->id,
    ));

    expect($leases)->toBe([])
        ->and(ProxyLease::query()->count())->toBe(0);
});

it('logs not-eligible for candidates without satisfied required evidence', function (): void {
    selTenant();

    $access = ProxyAccess::factory()->working()->create();
    ProxyHealth::query()->updateOrCreate(
        ['access_id' => $access->id],
        ['health_score' => 90, 'health_formula_version' => 'v1', 'computed_at' => now()],
    );

    $pool = selPoolWith([$access]);

    $leases = selSelector()->acquire((string) $pool->tenant_id, new SelectionCriteria(
        holder: 'consumer-1',
        poolId: $pool->id,
    ));

    expect($leases)->toBe([])
        ->and(ProxyPoolDecision::query()->where('access_id', $access->id)->first()->reason_code)
        ->toBe(SelectionReasonCode::NotEligible->value);
});

it('rotates with round robin across repeated acquires', function (): void {
    selTenant();

    $accesses = [selEligibleWorkingAccess(), selEligibleWorkingAccess(), selEligibleWorkingAccess()];
    $pool = selPoolWith($accesses);

    $selector = selSelector(new RoundRobinSelectionStrategy('rr-test'));

    $first = $selector->acquire((string) $pool->tenant_id, new SelectionCriteria(holder: 'h', poolId: $pool->id));
    $second = $selector->acquire((string) $pool->tenant_id, new SelectionCriteria(holder: 'h', poolId: $pool->id));

    expect($first[0]->accessId)->not->toBe($second[0]->accessId);
});

it('prefers higher scores with the weighted strategy', function (): void {
    selTenant();

    $low = selEligibleWorkingAccess();
    ProxyHealth::query()->where('access_id', $low->id)->update(['health_score' => 40]);

    $high = selEligibleWorkingAccess();
    ProxyHealth::query()->where('access_id', $high->id)->update(['health_score' => 95]);

    $pool = selPoolWith([$low, $high]);

    $leases = selSelector(new WeightedSelectionStrategy)->acquire((string) $pool->tenant_id, new SelectionCriteria(holder: 'h', poolId: $pool->id));

    expect(count($leases))->toBe(1)
        ->and($leases[0]->accessId)->toBe($high->id);
});

it('prefers never-leased members with the least-used strategy', function (): void {
    selTenant();

    $leased = selEligibleWorkingAccess();
    $never = selEligibleWorkingAccess();
    $pool = selPoolWith([$leased, $never]);

    // History: the first access was leased before.
    ProxyLease::query()->create([
        'access_id' => $leased->id,
        'holder' => 'old',
        'state' => 'released',
        'active_marker' => null,
        'acquired_at' => now()->subHour(),
        'expires_at' => now()->subMinutes(50),
        'released_at' => now()->subMinutes(49),
    ]);

    $leases = selSelector(new LeastUsedSelectionStrategy)->acquire((string) $pool->tenant_id, new SelectionCriteria(holder: 'h', poolId: $pool->id));

    expect(count($leases))->toBe(1)
        ->and($leases[0]->accessId)->toBe($never->id);
});

it('uses random strategy without crashing on repeated runs', function (): void {
    selTenant();

    $pool = selPoolWith([selEligibleWorkingAccess(), selEligibleWorkingAccess()]);

    $leases = selSelector(new RandomSelectionStrategy)->acquire((string) $pool->tenant_id, new SelectionCriteria(holder: 'h', poolId: $pool->id));

    expect(count($leases))->toBe(1);
});
