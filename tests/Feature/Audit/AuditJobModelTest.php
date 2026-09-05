<?php

declare(strict_types=1);

use App\Models\User;
use BAGArt\ProxyOperations\Domain\Probe\ProbeProfile;
use BAGArt\ProxyOperations\Domain\Snapshot\AuditPolicySnapshot;
use BAGArt\ProxyOperations\Models\AuditAttemptStatus;
use BAGArt\ProxyOperations\Models\AuditJobStatus;
use BAGArt\ProxyOperations\Models\AuditTrigger;
use BAGArt\ProxyOperations\Models\ImmutableRecordException;
use BAGArt\ProxyOperations\Models\PolicySnapshot;
use BAGArt\ProxyOperations\Models\ProxyAuditAttempt;
use BAGArt\ProxyOperations\Models\ProxyAuditJob;
use BAGArt\ProxyOperations\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function auditModelTenantUser(): User
{
    return User::factory()->create();
}

function auditModelTenant(): int
{
    $userId = auditModelTenantUser()->id;
    app(TenantContext::class)->set($userId);

    return $userId;
}

it('creates the audit pipeline tables with expected columns', function (): void {
    expect(Schema::hasTable('policy_snapshots'))->toBeTrue()
        ->and(Schema::hasColumns('policy_snapshots', [
            'id', 'tenant_id', 'policy_version', 'snapshot', 'created_at',
        ]))->toBeTrue()
        ->and(Schema::hasColumn('policy_snapshots', 'updated_at'))->toBeFalse()
        ->and(Schema::hasTable('proxy_audit_jobs'))->toBeTrue()
        ->and(Schema::hasColumns('proxy_audit_jobs', [
            'id', 'tenant_id', 'trigger', 'policy_snapshot_id', 'requested_by',
            'target_set_hash', 'status', 'result_code',
            'created_at', 'started_at', 'completed_at',
        ]))->toBeTrue()
        ->and(Schema::hasTable('proxy_audit_attempts'))->toBeTrue()
        ->and(Schema::hasColumns('proxy_audit_attempts', [
            'id', 'job_id', 'tenant_id', 'attempt_no', 'worker_node',
            'status', 'result_code', 'started_at', 'finished_at',
        ]))->toBeTrue();
});

it('enumerates audit triggers and statuses per plan naming', function (): void {
    expect(array_column(AuditTrigger::cases(), 'value'))->toBe([
        'manual', 'scheduled', 'import', 'feed', 'lazy_selection', 'recovery', 'tg_check',
    ])->and(array_column(AuditJobStatus::cases(), 'value'))->toBe([
        'pending', 'queued', 'running', 'completed', 'failed', 'cancelled',
    ])->and(array_column(AuditAttemptStatus::cases(), 'value'))->toBe([
        'pending', 'delivered', 'running', 'completed', 'failed', 'lost',
    ]);
});

it('creates a job with trigger, status and snapshot link inside tenant context', function (): void {
    $tenantId = auditModelTenant();

    $snapshot = PolicySnapshot::factory()->create();
    $job = ProxyAuditJob::factory()
        ->withTrigger(AuditTrigger::LazySelection)
        ->create(['policy_snapshot_id' => $snapshot->id]);
    $job->refresh();

    expect($job->tenant_id)->toBe($tenantId)
        ->and($job->trigger)->toBe(AuditTrigger::LazySelection)
        ->and($job->trigger->value)->toBe('lazy_selection')
        ->and($job->status)->toBe(AuditJobStatus::Pending)
        ->and($job->policy_snapshot_id)->toBe($snapshot->id)
        ->and($job->policySnapshot->is($snapshot))->toBeTrue()
        ->and($job->result_code)->toBeNull()
        ->and($job->updated_at)->toBeNull();
});

it('provides status and trigger scope helpers', function (): void {
    auditModelTenant();

    $running = ProxyAuditJob::factory()->withTrigger(AuditTrigger::Manual)->withStatus(AuditJobStatus::Running)->create();
    ProxyAuditJob::factory()->withTrigger(AuditTrigger::Feed)->withStatus(AuditJobStatus::Pending)->create();

    expect(ProxyAuditJob::query()->ofStatus(AuditJobStatus::Running)->count())->toBe(1)
        ->and(ProxyAuditJob::query()->ofStatus(AuditJobStatus::Running)->first()->is($running))->toBeTrue()
        ->and(ProxyAuditJob::query()->ofTrigger(AuditTrigger::Feed)->count())->toBe(1)
        ->and(ProxyAuditJob::query()->ofTrigger(AuditTrigger::Manual)->count())->toBe(1);
});

it('hides jobs and attempts of other tenants from queries (INV-006)', function (): void {
    $context = app(TenantContext::class);

    $context->set(auditModelTenant());
    $job = ProxyAuditJob::factory()->create();
    $attempt = ProxyAuditAttempt::factory()->create(['job_id' => $job->id]);
    $snapshot = PolicySnapshot::factory()->create();

    $context->set(auditModelTenant());

    expect(ProxyAuditJob::query()->whereKey($job->id)->exists())->toBeFalse()
        ->and(ProxyAuditJob::query()->count())->toBe(0)
        ->and(ProxyAuditAttempt::query()->whereKey($attempt->id)->exists())->toBeFalse()
        ->and(ProxyAuditAttempt::query()->count())->toBe(0)
        ->and(PolicySnapshot::query()->whereKey($snapshot->id)->exists())->toBeFalse()
        ->and(PolicySnapshot::query()->count())->toBe(0);
});

it('force-fills tenant_id on create regardless of supplied attributes (INV-006)', function (): void {
    $tenantId = auditModelTenant();
    $other = auditModelTenantUser()->id;

    $job = ProxyAuditJob::factory()->create();
    $job->refresh();

    expect($job->tenant_id)->toBe($tenantId)
        ->and($job->tenant_id)->not->toBe($other);
});

it('rejects a duplicate (job_id, attempt_no) pair', function (): void {
    auditModelTenant();

    $job = ProxyAuditJob::factory()->create();
    ProxyAuditAttempt::factory()->attemptNo(1)->create(['job_id' => $job->id]);
    ProxyAuditAttempt::factory()->attemptNo(1)->create(['job_id' => $job->id]);
})->throws(UniqueConstraintViolationException::class);

it('links attempts to their job and tenant', function (): void {
    auditModelTenant();

    $job = ProxyAuditJob::factory()->create();
    $attempt = ProxyAuditAttempt::factory()->attemptNo(2)->withStatus(AuditAttemptStatus::Running)->create([
        'job_id' => $job->id,
    ]);

    expect($attempt->job->is($job))->toBeTrue()
        ->and($attempt->tenant_id)->toBe($job->tenant_id)
        ->and($job->attempts()->count())->toBe(1)
        ->and($attempt->status)->toBe(AuditAttemptStatus::Running)
        ->and($attempt->updated_at)->toBeNull();
});

it('keeps policy snapshot rows immutable after create', function (): void {
    auditModelTenant();

    $snapshot = PolicySnapshot::factory()->create();
    $original = $snapshot->refresh()->getAttributes();

    expect(fn () => $snapshot->update(['policy_version' => 99]))
        ->toThrow(ImmutableRecordException::class)
        ->and(fn () => $snapshot->delete())
        ->toThrow(ImmutableRecordException::class)
        ->and($snapshot->refresh()->getAttributes())->toEqual($original)
        ->and(DB::table('policy_snapshots')->where('id', $snapshot->id)->exists())->toBeTrue();
});

it('roundtrips an AuditPolicySnapshot through the model bridge', function (): void {
    auditModelTenant();

    $dto = new AuditPolicySnapshot(
        id: 'psnap_roundtrip',
        policyVersion: 7,
        frozenAt: '2026-08-30T12:00:00+00:00',
        probeProfileMapping: [
            'manual' => ProbeProfile::Deep,
            'tg_check' => ProbeProfile::Telegram,
        ],
        healthThresholds: ['working_after_successes' => 3, 'dead_after_failures' => 5],
        quarantineRules: ['TCP_TIMEOUT' => 3],
    );

    $row = PolicySnapshot::fromDto($dto);

    expect($row->policy_version)->toBe(7)
        ->and($row->exists)->toBeTrue();

    $restored = $row->refresh()->toDto();

    expect($restored)->toEqual($dto)
        ->and($restored->id)->toBe('psnap_roundtrip')
        ->and($restored->probeProfileMapping)->toBe([
            'manual' => ProbeProfile::Deep,
            'tg_check' => ProbeProfile::Telegram,
        ])
        ->and($restored->healthThresholds)->toBe(['working_after_successes' => 3, 'dead_after_failures' => 5])
        ->and($restored->quarantineRules)->toBe(['TCP_TIMEOUT' => 3]);
});
