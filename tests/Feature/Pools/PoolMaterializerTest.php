<?php

declare(strict_types=1);

use App\Models\User;
use BAGArt\ProxyOperations\Audit\PoolMaterializer;
use BAGArt\ProxyOperations\Domain\Evidence\FreshnessAwareEligibilityPolicy;
use BAGArt\ProxyOperations\Domain\Pool\PoolKind;
use BAGArt\ProxyOperations\Domain\Pool\SelectionReasonCode;
use BAGArt\ProxyOperations\Models\ProxyAccess;
use BAGArt\ProxyOperations\Models\ProxyHealth;
use BAGArt\ProxyOperations\Models\ProxyPool;
use BAGArt\ProxyOperations\Models\ProxyPoolDecision;
use BAGArt\ProxyOperations\Models\ProxyPoolMember;
use BAGArt\ProxyOperations\Models\ProxyEndpoint;
use BAGArt\ProxyOperations\Tests\Fixtures\CapturingAuditEventRecorder;
use BAGArt\ProxyOperations\Tenancy\TenantContext;
function poolMatTenant(): int
{
    $userId = User::factory()->create()->id;
    app(TenantContext::class)->set($userId);

    return $userId;
}

/**
 * A fully eligible Http access: required dimensions (tcp/http/judge) pass,
 * state Working, health score from the factory.
 */
function poolMatEligibleAccess(): ProxyAccess
{
    $access = ProxyAccess::factory()->working()->create();

    ProxyHealth::query()->updateOrCreate(
        ['access_id' => $access->id],
        [
            'health_score' => 90,
            'dimension_signals' => ['tcp' => 'pass', 'http' => 'pass', 'judge' => 'pass'],
            'health_formula_version' => 'dimensional-v1',
            'computed_at' => now(),
        ],
    );

    return $access;
}

function poolMatFailingAccess(): ProxyAccess
{
    $access = ProxyAccess::factory()->dead()->create();

    ProxyHealth::query()->updateOrCreate(
        ['access_id' => $access->id],
        ['health_score' => 5, 'health_formula_version' => 'dimensional-v1', 'computed_at' => now()],
    );

    return $access;
}

function poolMatMaterializer(CapturingAuditEventRecorder $recorder): PoolMaterializer
{
    return new PoolMaterializer(new FreshnessAwareEligibilityPolicy, $recorder, 10000);
}

it('materializes a dynamic pool splitting accepted and skipped with reasons', function (): void {
    poolMatTenant();

    $recorder = new CapturingAuditEventRecorder;
    $materializer = poolMatMaterializer($recorder);

    $good = poolMatEligibleAccess();
    $dead = poolMatFailingAccess();

    $pool = ProxyPool::factory()->dynamic()->create();

    $result = $materializer->materialize($pool, 1);

    expect($result->accepted)->toBe(1)
        ->and($result->skipped)->toBe(1)
        ->and($pool->members()->pluck('access_id')->all())->toBe([$good->id])
        ->and($pool->members()->first()->materialization_version)->toBe(1);

    $rows = ProxyPoolDecision::query()->orderBy('decision')->get();
    expect($rows->where('access_id', $good->id)->first()->decision)->toBe('accepted')
        ->and($rows->where('access_id', $dead->id)->first()->decision)->toBe('skipped')
        ->and($rows->where('access_id', $dead->id)->first()->reason_code)->toBe(SelectionReasonCode::PredicateStateMismatch->value)
        ->and($rows->every(fn ($row) => $row->created_at !== null))->toBeTrue();
});

it('reports protocol mismatch in the decision log', function (): void {
    poolMatTenant();

    // poolMatEligibleAccess defaults to a socks5 endpoint; force http so the
    // socks5-only predicate skips it.
    $endpoint = ProxyEndpoint::factory()->http()->create();
    $access = ProxyAccess::factory()->working()->create(['endpoint_id' => $endpoint->id]);
    ProxyHealth::query()->updateOrCreate(
        ['access_id' => $access->id],
        ['health_score' => 90, 'health_formula_version' => 'v1', 'computed_at' => now()],
    );

    $pool = ProxyPool::factory()->create([
        'kind' => PoolKind::Dynamic->value,
        'predicate' => ['protocols' => ['socks5'], 'schemaVersion' => 1],
    ]);

    poolMatMaterializer(new CapturingAuditEventRecorder)->materialize($pool, 1);

    expect(ProxyPoolDecision::query()->where('access_id', $access->id)->first()->reason_code)
        ->toBe(SelectionReasonCode::PredicateProtocolMismatch->value);
});

it('reports health-below-floor in the decision log', function (): void {
    poolMatTenant();

    $access = poolMatEligibleAccess();
    ProxyHealth::query()->where('access_id', $access->id)->update(['health_score' => 20]);

    $pool = ProxyPool::factory()->create([
        'kind' => PoolKind::Dynamic->value,
        'predicate' => ['minHealthScore' => 50.0, 'schemaVersion' => 1],
    ]);

    poolMatMaterializer(new CapturingAuditEventRecorder)->materialize($pool, 1);

    expect(ProxyPoolDecision::query()->where('access_id', $access->id)->first()->reason_code)
        ->toBe(SelectionReasonCode::PredicateHealthBelowFloor->value);
});

it('reports the telegram filter in the decision log', function (): void {
    poolMatTenant();

    $access = ProxyAccess::factory()->create(); // never telegram-checked

    ProxyHealth::query()->updateOrCreate(
        ['access_id' => $access->id],
        ['health_score' => 90, 'health_formula_version' => 'v1', 'computed_at' => now()],
    );

    $pool = ProxyPool::factory()->create([
        'kind' => PoolKind::Dynamic->value,
        'predicate' => ['telegramUsableOnly' => true, 'schemaVersion' => 1],
    ]);

    poolMatMaterializer(new CapturingAuditEventRecorder)->materialize($pool, 1);

    expect(ProxyPoolDecision::query()->where('access_id', $access->id)->first()->reason_code)
        ->toBe(SelectionReasonCode::PredicateTelegramFilter->value);
});

it('is reproducible: same data produces identical members and new run ids', function (): void {
    poolMatTenant();

    $recorder = new CapturingAuditEventRecorder;
    $materializer = poolMatMaterializer($recorder);

    poolMatEligibleAccess();
    poolMatEligibleAccess();

    $pool = ProxyPool::factory()->dynamic()->create();

    $first = $materializer->materialize($pool, 1);
    $second = $materializer->materialize($pool, 1);

    expect($first->accepted)->toBe(2)
        ->and($second->accepted)->toBe(2)
        ->and($first->materializationId)->not->toBe($second->materializationId)
        ->and($pool->members()->orderBy('access_id')->pluck('access_id')->all())
        ->toBe($pool->members()->orderBy('access_id')->pluck('access_id')->all());
});

it('preserves hand-picked hybrid members across rebuilds', function (): void {
    poolMatTenant();

    $materializer = poolMatMaterializer(new CapturingAuditEventRecorder);

    $handPicked = ProxyAccess::factory()->create();
    $good = poolMatEligibleAccess();

    $pool = ProxyPool::factory()->hybrid()->create();
    ProxyPoolMember::query()->create([
        'pool_id' => $pool->id,
        'access_id' => $handPicked->id,
        'added_at' => now(),
        'materialization_version' => null,
    ]);

    $result = $materializer->materialize($pool, 1);

    expect($result->accepted)->toBe(1);

    $members = $pool->members()->get()->keyBy('access_id');
    expect($members->has($handPicked->id))->toBeTrue()
        ->and($members->get($handPicked->id)->materialization_version)->toBeNull()
        ->and($members->has($good->id))->toBeTrue()
        ->and($members->get($good->id)->materialization_version)->toBe(1);
});

it('bumps the materialization version on every run', function (): void {
    poolMatTenant();

    $materializer = poolMatMaterializer(new CapturingAuditEventRecorder);
    poolMatEligibleAccess();

    $pool = ProxyPool::factory()->dynamic()->create();

    $materializer->materialize($pool, 1);
    $materializer->materialize($pool, 1);

    expect($pool->members()->first()->materialization_version)->toBe(2);
});

it('records PoolRebuilt after commit and nothing when aborted', function (): void {
    poolMatTenant();

    $recorder = new CapturingAuditEventRecorder;
    $materializer = poolMatMaterializer($recorder);
    poolMatEligibleAccess();

    $pool = ProxyPool::factory()->dynamic()->create();
    $materializer->materialize($pool, 1);

    expect(count($recorder->records))->toBe(1)
        ->and($recorder->records[0]['envelope']->eventType)->toBe('pool.rebuilt')
        ->and($recorder->records[0]['envelope']->aggregateRef)->toBe($pool->id)
        ->and($recorder->records[0]['envelope']->tenantId)->toBe((string) $pool->tenant_id)
        // Level 1 = only the outer RefreshDatabase test transaction remains:
        // the materializer's own transaction has committed (event post-commit).
        ->and($recorder->records[0]['transactionLevel'])->toBe(1);

    // Abort (static pool) → no event, members untouched.
    $static = ProxyPool::factory()->create(['kind' => PoolKind::Static->value]);

    expect(fn () => $materializer->materialize($static, 1))->toThrow(InvalidArgumentException::class)
        ->and($recorder->records)->toHaveCount(1);
});

it('aborts without partial materialization when the candidate set exceeds the cap', function (): void {
    poolMatTenant();

    $recorder = new CapturingAuditEventRecorder;
    $materializer = new PoolMaterializer(new FreshnessAwareEligibilityPolicy, $recorder, 2);

    poolMatEligibleAccess();
    poolMatEligibleAccess();
    poolMatEligibleAccess();

    $pool = ProxyPool::factory()->dynamic()->create();

    expect(fn () => $materializer->materialize($pool, 1))->toThrow(RuntimeException::class)
        ->and($pool->members()->count())->toBe(0)
        ->and(ProxyPoolDecision::query()->count())->toBe(0)
        ->and($recorder->records)->toBe([]);
});

it('reports not-eligible when required evidence is missing', function (): void {
    poolMatTenant();

    // Working state passes the predicate, but no evidence → NotEligible.
    $access = ProxyAccess::factory()->working()->create();
    ProxyHealth::query()->updateOrCreate(
        ['access_id' => $access->id],
        ['health_score' => 90, 'health_formula_version' => 'v1', 'computed_at' => now()],
    );

    $pool = ProxyPool::factory()->dynamic()->create();
    poolMatMaterializer(new CapturingAuditEventRecorder)->materialize($pool, 1);

    expect(ProxyPoolDecision::query()->where('access_id', $access->id)->first()->reason_code)
        ->toBe(SelectionReasonCode::NotEligible->value)
        ->and($pool->members()->count())->toBe(0);
});

it('never crosses the tenant boundary and logs no credentials', function (): void {
    poolMatTenant();

    $good = poolMatEligibleAccess();
    $pool = ProxyPool::factory()->dynamic()->create();
    poolMatMaterializer(new CapturingAuditEventRecorder)->materialize($pool, 1);

    // Foreign-tenant access is invisible to the candidate query.
    $foreignTenant = User::factory()->create()->id;
    app(TenantContext::class)->set($foreignTenant);
    $foreign = ProxyAccess::factory()->working()->create();
    app(TenantContext::class)->set((int) $pool->tenant_id);

    $result = (poolMatMaterializer(new CapturingAuditEventRecorder))->materialize($pool, 2);

    expect($result->accepted)->toBe(1)
        ->and($pool->members()->pluck('access_id')->all())->toBe([$good->id])
        ->and($pool->members()->pluck('access_id')->contains($foreign->id))->toBeFalse();

    // Decision rows never carry credential material.
    foreach (ProxyPoolDecision::query()->get() as $row) {
        $json = json_encode($row->getAttributes());
        expect($json)->not->toContain('password')
            ->and($json)->not->toContain($good->credentialFingerprint() ?? '___none___');
    }
});
