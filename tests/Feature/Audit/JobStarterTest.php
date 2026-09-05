<?php

declare(strict_types=1);

use App\Models\User;
use BAGArt\ProxyOperations\Audit\AuditRequest;
use BAGArt\ProxyOperations\Audit\ForeignAccessIdException;
use BAGArt\ProxyOperations\Audit\JobStarter;
use BAGArt\ProxyOperations\Audit\PolicySnapshotBuilder;
use BAGArt\ProxyOperations\Domain\Snapshot\AuditPolicySnapshot;
use BAGArt\ProxyOperations\Models\AuditJobStatus;
use BAGArt\ProxyOperations\Models\AuditTrigger;
use BAGArt\ProxyOperations\Models\PolicySnapshot;
use BAGArt\ProxyOperations\Models\ProxyAccess;
use BAGArt\ProxyOperations\Models\ProxyAuditJob;
use BAGArt\ProxyOperations\Tenancy\TenantContext;
use BAGArt\ProxyOperations\Tenancy\TenantNotResolvedException;
use Illuminate\Support\Carbon;

function jobStarterTenantUser(): User
{
    return User::factory()->create();
}

function jobStarterTenant(): int
{
    $userId = jobStarterTenantUser()->id;
    app(TenantContext::class)->set($userId);

    return $userId;
}

/**
 * @return list<string>
 */
function jobStarterAccessIds(int $count = 2): array
{
    return ProxyAccess::factory()
        ->count($count)
        ->create()
        ->map(fn (ProxyAccess $access): string => $access->id)
        ->values()
        ->all();
}

function jobStarterRequest(array $accessIds, string $probeProfile = 'standard'): AuditRequest
{
    return new AuditRequest(
        trigger: AuditTrigger::Scheduled,
        probeProfile: $probeProfile,
        accessIds: $accessIds,
        requestedBy: app(TenantContext::class)->id(),
    );
}

it('creates a pending job with a persisted snapshot and the trigger set', function (): void {
    $tenantId = jobStarterTenant();
    $accessIds = jobStarterAccessIds();

    $job = app(JobStarter::class)->start(jobStarterRequest($accessIds));

    expect($job->exists)->toBeTrue()
        ->and($job->refresh()->tenant_id)->toBe($tenantId)
        ->and($job->status)->toBe(AuditJobStatus::Pending)
        ->and($job->trigger)->toBe(AuditTrigger::Scheduled)
        ->and($job->requested_by)->toBe($tenantId)
        ->and($job->result_code)->toBeNull()
        ->and($job->started_at)->toBeNull()
        ->and($job->target_set_hash)->toBeString();

    expect(PolicySnapshot::query()->count())->toBe(1)
        ->and($job->policySnapshot)->toBeInstanceOf(PolicySnapshot::class)
        ->and($job->policySnapshot->policy_version)->toBe(1)
        ->and($job->policySnapshot->toDto())->toBeInstanceOf(AuditPolicySnapshot::class)
        ->and($job->policySnapshot->toDto()->probeProfileMapping['scheduled']->value)->toBe('standard');
});

it('returns the same job for a duplicate placement inside the TTL window', function (): void {
    jobStarterTenant();
    $accessIds = jobStarterAccessIds();
    $starter = app(JobStarter::class);

    $first = $starter->start(jobStarterRequest($accessIds));
    $second = $starter->start(jobStarterRequest($accessIds));

    expect($second->is($first))->toBeTrue()
        ->and(ProxyAuditJob::query()->count())->toBe(1)
        ->and(PolicySnapshot::query()->count())->toBe(1);
});

it('creates a new job once the placement TTL window has expired', function (): void {
    jobStarterTenant();
    $accessIds = jobStarterAccessIds();
    $starter = app(JobStarter::class);

    $now = Carbon::now();
    Carbon::setTestNow($now);
    $first = $starter->start(jobStarterRequest($accessIds));

    Carbon::setTestNow($now->copy()->addSeconds(
        (int) config('proxy-operations.audit.placement_ttl_seconds') + 1,
    ));
    $second = $starter->start(jobStarterRequest($accessIds));

    Carbon::setTestNow();

    expect($second->is($first))->toBeFalse()
        ->and(ProxyAuditJob::query()->count())->toBe(2)
        ->and(PolicySnapshot::query()->count())->toBe(1);
});

it('reuses the snapshot row while the effective policy content is unchanged', function (): void {
    jobStarterTenant();
    $builder = app(PolicySnapshotBuilder::class);

    $first = $builder->build(AuditTrigger::Scheduled, 'standard');
    $second = $builder->build(AuditTrigger::Scheduled, 'standard');

    expect($second->is($first))->toBeTrue()
        ->and(PolicySnapshot::query()->count())->toBe(1)
        ->and($second->policy_version)->toBe($first->policy_version)
        ->and($second->id)->toBe($first->id);
});

it('creates a new snapshot row with a bumped policy_version when lifecycle thresholds change', function (): void {
    jobStarterTenant();
    $builder = app(PolicySnapshotBuilder::class);

    $first = $builder->build(AuditTrigger::Scheduled, 'standard');

    config()->set('proxy-operations.audit.lifecycle_thresholds.dead_after_failures', 12);

    $second = $builder->build(AuditTrigger::Scheduled, 'standard');

    expect($second->is($first))->toBeFalse()
        ->and(PolicySnapshot::query()->count())->toBe(2)
        ->and($second->policy_version)->toBe($first->policy_version + 1)
        ->and($second->id)->not->toBe($first->id)
        ->and($second->toDto()->healthThresholds['dead_after_failures'])->toBe(12)
        ->and($first->toDto()->healthThresholds['dead_after_failures'])->toBe(10);
});

it('keeps WorkspacePolicy fields out of the serialized snapshot', function (): void {
    jobStarterTenant();
    $accessIds = jobStarterAccessIds();

    config()->set('proxy-operations.retention.enabled', true);
    config()->set('proxy-operations.ui_flags.web_panel_enabled', true);
    config()->set('proxy-operations.export_rules.with_credentials_opt_in', true);

    $job = app(JobStarter::class)->start(jobStarterRequest($accessIds));

    $payload = $job->policySnapshot->snapshot;

    expect(array_keys($payload))->toEqualCanonicalizing([
        'id', 'policyVersion', 'frozenAt', 'probeProfileMapping',
        'healthThresholds', 'quarantineRules', 'schemaVersion',
    ])->and($payload)->not->toHaveKey('retention')
        ->and($payload)->not->toHaveKey('ui_flags')
        ->and($payload)->not->toHaveKey('quotas')
        ->and($payload)->not->toHaveKey('export_rules');
});

it('rejects foreign-tenant access ids without persisting anything', function (): void {
    $context = app(TenantContext::class);

    $context->set(jobStarterTenantUser()->id);
    $foreignIds = ProxyAccess::factory()->count(1)->create()
        ->map(fn (ProxyAccess $access): string => $access->id)
        ->values()->all();

    $context->set(jobStarterTenant());

    app(JobStarter::class)->start(jobStarterRequest($foreignIds));
})->throws(ForeignAccessIdException::class);

it('persists nothing when the placement fails on a foreign access id', function (): void {
    $context = app(TenantContext::class);

    $ownerId = jobStarterTenantUser()->id;
    $context->set($ownerId);
    $foreignIds = ProxyAccess::factory()->count(2)->create()
        ->map(fn (ProxyAccess $access): string => $access->id)
        ->values()->all();

    $context->set(jobStarterTenant());

    try {
        app(JobStarter::class)->start(jobStarterRequest($foreignIds));
    } catch (ForeignAccessIdException) {
        // expected
    }

    $context->set($ownerId);

    expect(ProxyAuditJob::query()->count())->toBe(0)
        ->and(PolicySnapshot::query()->count())->toBe(0)
        ->and(ProxyAccess::query()->count())->toBe(2);
});

it('fails closed when no tenant is resolved in the current scope', function (): void {
    app(TenantContext::class)->forget();

    app(JobStarter::class)->start(jobStarterRequest([]));
})->throws(TenantNotResolvedException::class);
