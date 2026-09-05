<?php

declare(strict_types=1);

use App\Models\User;
use BAGArt\ProxyOperations\Domain\Failure\FailureClass;
use BAGArt\ProxyOperations\Domain\Failure\FailureCode;
use BAGArt\ProxyOperations\Domain\Probe\ProbeProfile;
use BAGArt\ProxyOperations\Domain\Probe\ProbeType;
use BAGArt\ProxyOperations\Models\ImmutableRecordException;
use BAGArt\ProxyOperations\Models\ProxyObservation;
use BAGArt\ProxyOperations\Tenancy\TenantContext;
use BAGArt\ProxyOperations\Tenancy\TenantNotResolvedException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function createObservationTenantUser(): User
{
    return User::factory()->create();
}

function observationTenant(): int
{
    $userId = createObservationTenantUser()->id;
    app(TenantContext::class)->set($userId);

    return $userId;
}

it('creates the proxy_observations table with expected columns, no updated_at, and descending indexes', function (): void {
    expect(Schema::hasTable('proxy_observations'))->toBeTrue()
        ->and(Schema::hasColumns('proxy_observations', [
            'id', 'tenant_id', 'access_id', 'checked_at',
            'probe_type', 'probe_profile', 'probe_profile_version',
            'judge_set_version', 'checker_node_id', 'checker_region',
            'outcome', 'failure_code', 'failure_class',
            'evidence', 'schema_version', 'created_at',
        ]))->toBeTrue()
        ->and(Schema::hasColumn('proxy_observations', 'updated_at'))->toBeFalse();

    $indexes = collect(DB::select("PRAGMA index_list('proxy_observations')"));

    $tenantIndex = $indexes->firstWhere(fn (object $index) => str_contains((string) $index->name, 'tenant_id'));
    $accessIndex = $indexes->firstWhere(fn (object $index) => str_contains((string) $index->name, 'access_id')
        && ! str_contains((string) $index->name, 'tenant_id'));

    expect($tenantIndex)->not->toBeNull()
        ->and($accessIndex)->not->toBeNull();

    $tenantColumns = collect(DB::select("PRAGMA index_xinfo('{$tenantIndex->name}')"))
        ->filter(fn (object $column) => $column->key === 1)
        ->values();
    $accessColumns = collect(DB::select("PRAGMA index_xinfo('{$accessIndex->name}')"))
        ->filter(fn (object $column) => $column->key === 1)
        ->values();

    expect($tenantColumns->pluck('name')->all())->toBe(['tenant_id', 'access_id', 'checked_at'])
        ->and($tenantColumns->last()->desc)->toEqual(1)
        ->and($accessColumns->pluck('name')->all())->toBe(['access_id', 'checked_at'])
        ->and($accessColumns->last()->desc)->toEqual(1);
});

it('creates an observation within tenant context with enum casts and defaults', function (): void {
    $tenantId = observationTenant();

    $observation = ProxyObservation::factory()->successful()->create();
    $observation->refresh();

    expect($observation->tenant_id)->toBe($tenantId)
        ->and($observation->probe_type)->toBe(ProbeType::HttpLiveness)
        ->and($observation->probe_profile)->toBe(ProbeProfile::Standard)
        ->and($observation->outcome)->toBe('success')
        ->and($observation->failure_code)->toBeNull()
        ->and($observation->failure_class)->toBeNull()
        ->and($observation->schema_version)->toBe(1)
        ->and($observation->evidence)->toBeArray()
        ->and($observation->updated_at)->toBeNull();
});

it('roundtrips a failed observation through the FailureCode and FailureClass casts', function (): void {
    observationTenant();

    $at = Carbon::parse('2026-08-27 12:00:00');
    $observation = ProxyObservation::factory()
        ->failed(FailureCode::TcpTimeout)
        ->checkedAt($at)
        ->create();
    $observation->refresh();

    expect($observation->outcome)->toBe('failure')
        ->and($observation->failure_code)->toBe(FailureCode::TcpTimeout)
        ->and($observation->failure_class)->toBe(FailureClass::Proxy)
        ->and($observation->checked_at->equalTo($at))->toBeTrue();
});

it('accepts every non-execution failure class as a valid observation failure', function (FailureCode $code): void {
    observationTenant();

    $observation = ProxyObservation::factory()->failed($code)->create();

    expect($observation->failure_code)->toBe($code)
        ->and($observation->failure_class)->toBe($code->class());
})->with([
    'proxy class' => FailureCode::AuthFailure,
    'target class' => FailureCode::Target5xx,
    'judge class' => FailureCode::JudgeInconsistent,
    'policy class' => FailureCode::UnsupportedProtocol,
]);

it('rejects a failure code outside the FailureCode vocabulary', function (): void {
    observationTenant();

    // The enum cast is the first persistence gate: an unknown value can never
    // be assigned as failure_code at all.
    ProxyObservation::factory()->create([
        'outcome' => 'failure',
        'failure_code' => 'TOTALLY_NOT_A_CODE',
        'failure_class' => 'proxy',
    ]);
})->throws(ValueError::class);

it('rejects Checker-class execution failures as observation failures (INV-014)', function (): void {
    observationTenant();

    ProxyObservation::factory()->failed(FailureCode::ToolTimeout)->create();
})->throws(InvalidArgumentException::class);

it('rejects Platform-class execution failures as observation failures (INV-015)', function (): void {
    observationTenant();

    ProxyObservation::factory()->failed(FailureCode::RedisUnavailable)->create();
})->throws(InvalidArgumentException::class);

it('rejects a failure code on a successful outcome and a missing code on a failed one', function (): void {
    observationTenant();

    expect(fn () => ProxyObservation::factory()->successful()->create([
        'failure_code' => FailureCode::TcpTimeout->value,
        'failure_class' => FailureClass::Proxy->value,
    ]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => ProxyObservation::factory()->create(['outcome' => 'failure']))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects evidence carrying tenant interpretation keys', function (): void {
    observationTenant();

    ProxyObservation::factory()->create([
        'evidence' => ['status' => 200, 'health_score' => 0.9],
    ]);
})->throws(InvalidArgumentException::class);

it('rejects evidence carrying secret-bearing keys at any nesting depth', function (): void {
    observationTenant();

    expect(fn () => ProxyObservation::factory()->create([
        'evidence' => ['status' => 200, 'headers' => ['proxy_authorization' => 'Basic xxx']],
    ]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => ProxyObservation::factory()->create([
            'evidence' => ['dns' => ['resolver' => ['session_token' => 'abc']]],
        ]))->toThrow(InvalidArgumentException::class);
});

it('raises on update attempts after persist', function (): void {
    observationTenant();

    $observation = ProxyObservation::factory()->successful()->create();

    $observation->update(['evidence' => ['status' => 500]]);
})->throws(ImmutableRecordException::class);

it('raises on attribute change and save of a persisted observation', function (): void {
    observationTenant();

    $observation = ProxyObservation::factory()->successful()->create();
    $original = $observation->refresh()->getAttributes();

    try {
        $observation->forceFill(['checker_region' => 'eu-west'])->save();
        $this->fail('Expected ImmutableRecordException.');
    } catch (ImmutableRecordException) {
        // expected — the row must remain untouched
    }

    expect($observation->refresh()->getAttributes())->toEqual($original);
});

it('raises on delete attempts', function (): void {
    observationTenant();

    $observation = ProxyObservation::factory()->successful()->create();
    $id = $observation->id;

    try {
        $observation->delete();
        $this->fail('Expected ImmutableRecordException.');
    } catch (ImmutableRecordException) {
        // expected
    }

    expect(fn () => $observation->refresh())->not->toThrow(Exception::class)
        ->and(DB::table('proxy_observations')->where('id', $id)->exists())->toBeTrue();
});

it('hides observations of other tenants from queries', function (): void {
    $context = app(TenantContext::class);

    $context->set(observationTenant());
    $mine = ProxyObservation::factory()->successful()->create();

    $context->set(observationTenant());

    expect(ProxyObservation::query()->whereKey($mine->id)->exists())->toBeFalse()
        ->and(ProxyObservation::query()->count())->toBe(0);
});

it('throws on queries and creates without a resolved tenant instead of falling back to unscoped', function (): void {
    app(TenantContext::class)->forget();

    expect(fn () => ProxyObservation::query()->get())->toThrow(TenantNotResolvedException::class);

    app(TenantContext::class)->forget();
    $accessId = (function (): string {
        app(TenantContext::class)->set(createObservationTenantUser()->id);
        $accessId = ProxyObservation::factory()->successful()->create()->access_id;
        app(TenantContext::class)->forget();

        return $accessId;
    })();

    expect(fn () => ProxyObservation::factory()->create(['access_id' => $accessId]))
        ->toThrow(TenantNotResolvedException::class);
});
