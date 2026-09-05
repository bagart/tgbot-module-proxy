<?php

declare(strict_types=1);

use App\Models\User;
use BAGArt\ProxyOperations\Domain\Identity\ProxyProtocol;
use BAGArt\ProxyOperations\Domain\Lifecycle\AccessState;
use BAGArt\ProxyOperations\Domain\Pool\PoolCandidateView;
use BAGArt\ProxyOperations\Domain\Pool\PoolKind;
use BAGArt\ProxyOperations\Domain\Pool\PoolPredicate;
use BAGArt\ProxyOperations\Models\ProxyAccess;
use BAGArt\ProxyOperations\Models\ProxyHealth;
use BAGArt\ProxyOperations\Models\ProxyPool;
use BAGArt\ProxyOperations\Models\ProxyPoolMember;
use BAGArt\ProxyOperations\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

function poolTestTenant(): int
{
    $userId = User::factory()->create()->id;
    app(TenantContext::class)->set($userId);

    return $userId;
}

function poolCandidate(ProxyAccess $access, ?ProxyHealth $health): PoolCandidateView
{
    return PoolCandidateView::fromModels($access, $health);
}

it('creates the pool tables with expected columns and constraints', function (): void {
    poolTestTenant();

    expect(Schema::hasTable('proxy_pools'))->toBeTrue()
        ->and(Schema::hasColumns('proxy_pools', [
            'id', 'tenant_id', 'name', 'kind', 'predicate', 'enabled',
            'description', 'policy_version',
            'last_materialization_id', 'last_materialized_at',
            'created_at', 'updated_at',
        ]))->toBeTrue()
        ->and(Schema::hasTable('proxy_pool_members'))->toBeTrue()
        ->and(Schema::hasColumns('proxy_pool_members', [
            'id', 'tenant_id', 'pool_id', 'access_id', 'added_at',
            'materialization_version',
        ]))->toBeTrue();
});

it('round-trips pools of all three kinds with predicate casting', function (): void {
    poolTestTenant();

    $static = ProxyPool::query()->create(['name' => 'static-a', 'kind' => PoolKind::Static->value]);
    $dynamic = ProxyPool::query()->create([
        'name' => 'dynamic-a',
        'kind' => PoolKind::Dynamic->value,
        'predicate' => ['states' => ['working'], 'minHealthScore' => 70.0, 'schemaVersion' => 1],
    ]);

    expect($static->kind)->toBe(PoolKind::Static)
        ->and($static->predicateDto())->toBeNull()
        ->and($static->enabled)->toBeTrue()
        ->and($static->policy_version)->toBe(1)
        ->and($dynamic->kind)->toBe(PoolKind::Dynamic)
        ->and($dynamic->predicateDto())->toBeInstanceOf(PoolPredicate::class)
        ->and($dynamic->predicateDto()?->states)->toBe(['working'])
        ->and($dynamic->predicateDto()?->minHealthScore)->toBe(70.0);
});

it('enforces unique pool name per tenant and unique pool+access membership', function (): void {
    poolTestTenant();

    ProxyPool::query()->create(['name' => 'dup', 'kind' => PoolKind::Static->value]);

    expect(fn (): Model => ProxyPool::query()->create(['name' => 'dup', 'kind' => PoolKind::Static->value]))
        ->toThrow(UniqueConstraintViolationException::class);

    $pool = ProxyPool::query()->create(['name' => 'members', 'kind' => PoolKind::Static->value]);
    $access = ProxyAccess::factory()->create();

    ProxyPoolMember::query()->create([
        'pool_id' => $pool->id,
        'access_id' => $access->id,
        'added_at' => now(),
    ]);

    expect(fn (): Model => ProxyPoolMember::query()->create([
        'pool_id' => $pool->id,
        'access_id' => $access->id,
        'added_at' => now(),
    ]))->toThrow(UniqueConstraintViolationException::class);
});

it('evaluates the predicate as a conjunction with null pass-through', function (): void {
    poolTestTenant();

    $working = ProxyAccess::factory()->working()->create();
    $dead = ProxyAccess::factory()->dead()->create();

    $health = ProxyHealth::factory()->healthy()->create(['access_id' => $working->id]);

    $workingView = poolCandidate($working->fresh(), $health);
    $deadView = poolCandidate($dead->fresh(), null);

    $open = new PoolPredicate;
    expect($open->matches($workingView))->toBeTrue()
        ->and($open->matches($deadView))->toBeTrue();

    $byState = new PoolPredicate(states: [AccessState::Working->value]);
    expect($byState->matches($workingView))->toBeTrue()
        ->and($byState->matches($deadView))->toBeFalse();

    $byProtocol = new PoolPredicate(protocols: [ProxyProtocol::Socks5->value]);
    expect($byProtocol->matches($workingView))->toBe($workingView->protocol === ProxyProtocol::Socks5);

    $byHealth = new PoolPredicate(minHealthScore: 80.0);
    expect($byHealth->matches($workingView))->toBeTrue()
        ->and($byHealth->matches($deadView))->toBeFalse();

    $byTelegram = new PoolPredicate(telegramUsableOnly: true);
    expect($byTelegram->matches($workingView))->toBeTrue()
        ->and($byTelegram->matches($deadView))->toBeFalse();
});

it('round-trips the predicate DTO through JSON', function (): void {
    poolTestTenant();

    $predicate = new PoolPredicate(
        states: ['working', 'degraded'],
        protocols: ['socks5'],
        minHealthScore: 55.5,
        telegramUsableOnly: true,
    );

    $decoded = PoolPredicate::fromJson($predicate->jsonSerialize());

    expect($decoded->states)->toBe(['working', 'degraded'])
        ->and($decoded->protocols)->toBe(['socks5'])
        ->and($decoded->minHealthScore)->toBe(55.5)
        ->and($decoded->telegramUsableOnly)->toBeTrue();

    expect(fn (): PoolPredicate => PoolPredicate::fromJson(['schemaVersion' => 99]))
        ->toThrow(RuntimeException::class);
});
