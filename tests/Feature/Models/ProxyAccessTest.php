<?php

declare(strict_types=1);

use App\Models\User;
use BAGArt\ProxyOperations\Domain\Lifecycle\AccessState;
use BAGArt\ProxyOperations\Domain\Lifecycle\HealthSignal;
use BAGArt\ProxyOperations\Domain\Lifecycle\QuarantineStatus;
use BAGArt\ProxyOperations\Domain\Lifecycle\TestabilityStatus;
use BAGArt\ProxyOperations\Models\ProxyAccess;
use BAGArt\ProxyOperations\Models\ProxyCredential;
use BAGArt\ProxyOperations\Models\ProxyEndpoint;
use BAGArt\ProxyOperations\Tenancy\TenantContext;
use BAGArt\ProxyOperations\Tenancy\TenantNotResolvedException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

function createAccessTenantUser(): User
{
    return User::factory()->create();
}

function accessTenant(): int
{
    $userId = createAccessTenantUser()->id;
    app(TenantContext::class)->set($userId);

    return $userId;
}

it('creates the proxy_accesses table with expected columns and indexes', function (): void {
    expect(Schema::hasTable('proxy_accesses'))->toBeTrue()
        ->and(Schema::hasColumns('proxy_accesses', [
            'id', 'tenant_id', 'endpoint_id', 'credential_id',
            'access_identity_hash', 'state', 'testability_status',
            'quarantine_status', 'quarantine_reason', 'consecutive_failures',
            'last_checked_at', 'state_changed_at',
            'telegram_connectivity', 'telegram_usable',
            'telegram_checked_at', 'telegram_fresh_until',
            'telegram_evidence_version', 'last_transition_event',
            'created_at', 'updated_at',
        ]))->toBeTrue();

    $indexes = collect(DB::select("PRAGMA index_list('proxy_accesses')"));
    $uniqueIndex = $indexes->firstWhere(fn (object $index) => (bool) $index->unique
        && str_contains((string) $index->name, 'access_identity_hash'));
    $stateIndex = $indexes->firstWhere(fn (object $index) => ! ((bool) $index->unique)
        && str_contains((string) $index->name, 'state'));
    $endpointIndex = $indexes->firstWhere(fn (object $index) => str_contains((string) $index->name, 'endpoint_id'));

    expect($uniqueIndex)->not->toBeNull()
        ->and($stateIndex)->not->toBeNull()
        ->and($endpointIndex)->not->toBeNull();

    $uniqueColumns = collect(DB::select("PRAGMA index_info('{$uniqueIndex->name}')"))
        ->pluck('name')->all();

    expect($uniqueColumns)->toBe(['tenant_id', 'access_identity_hash']);
});

it('creates an access within tenant context with defaults and derived identity hash', function (): void {
    $tenantId = accessTenant();

    $endpoint = ProxyEndpoint::factory()->create(['host' => '1.2.3.4']);
    $access = ProxyAccess::factory()->for($endpoint, 'endpoint')->create();

    $expectedHash = ProxyAccess::identityHash($endpoint->identity(), $access->credentialFingerprint());

    expect($access->tenant_id)->toBe($tenantId)
        ->and($access->state)->toBe(AccessState::New)
        ->and($access->testability_status)->toBe(TestabilityStatus::Testable)
        ->and($access->quarantine_status)->toBe(QuarantineStatus::None)
        ->and($access->quarantine_reason)->toBeNull()
        ->and($access->consecutive_failures)->toBe(0)
        ->and($access->credential)->toBeInstanceOf(ProxyCredential::class)
        ->and($access->access_identity_hash)->toBe($expectedHash);
});

it('rejects a duplicate (tenant, access_identity_hash) at DB level but allows the same identity in another tenant', function (): void {
    $context = app(TenantContext::class);

    $context->set(createAccessTenantUser()->id);
    $endpoint = ProxyEndpoint::factory()->create(['host' => 'proxy.example.com', 'port' => 1080]);
    $credential = ProxyCredential::factory()->create([
        'endpoint_id' => $endpoint->id,
        'username' => 'shared-user',
        'secret' => 'shared-passphrase',
    ]);
    $mine = ProxyAccess::factory()->for($endpoint, 'endpoint')->create(['credential_id' => $credential->id]);

    expect(fn (): ProxyAccess => ProxyAccess::factory()
        ->for($endpoint, 'endpoint')
        ->create(['credential_id' => $credential->id]))
        ->toThrow(UniqueConstraintViolationException::class);

    // Same endpoint identity + same credential payload → identical
    // AccessIdentity; allowed because uniqueness is tenant-scoped.
    $context->set(createAccessTenantUser()->id);
    $otherEndpoint = ProxyEndpoint::factory()->create(['host' => 'PROXY.example.com.', 'port' => 1080]);
    $otherCredential = ProxyCredential::factory()->create([
        'endpoint_id' => $otherEndpoint->id,
        'username' => 'shared-user',
        'secret' => 'shared-passphrase',
    ]);
    $theirs = ProxyAccess::factory()->for($otherEndpoint, 'endpoint')
        ->create(['credential_id' => $otherCredential->id]);

    expect($theirs->access_identity_hash)->toBe($mine->access_identity_hash)
        ->and(ProxyAccess::query()->count())->toBe(1);
});

it('walks the legal path New → Testing → Working and records the transition events', function (): void {
    accessTenant();

    $access = ProxyAccess::factory()->create([
        'telegram_usable' => true,
        'telegram_checked_at' => now(),
        'telegram_fresh_until' => now()->addMinutes(10),
    ]);

    $testingEvent = $access->recordTransition(AccessState::Testing, HealthSignal::RecheckTriggered);

    expect($access->refresh()->state)->toBe(AccessState::Testing)
        ->and($access->state_changed_at)->not->toBeNull()
        ->and($testingEvent->from)->toBe(AccessState::New)
        ->and($testingEvent->to)->toBe(AccessState::Testing)
        // occurredAt loses sub-second precision through the datetime column,
        // so the round-tripped DTO is compared field-wise.
        ->and($access->lastTransitionEvent()?->to)->toBe($testingEvent->to)
        ->and($access->lastTransitionEvent()?->from)->toBe($testingEvent->from)
        ->and($access->lastTransitionEvent()?->reason)->toBeNull();

    $workingEvent = $access->recordTransition(AccessState::Working, HealthSignal::ProbeSucceeded);

    expect($access->refresh()->state)->toBe(AccessState::Working)
        ->and($workingEvent->to)->toBe(AccessState::Working)
        ->and($workingEvent->reason)->toBeNull()
        ->and($access->lastTransitionEvent()?->to)->toBe(AccessState::Working);
});

it('rejects an illegal lifecycle jump and leaves the state unchanged', function (): void {
    accessTenant();

    $access = ProxyAccess::factory()->create();
    $before = $access->state_changed_at;

    try {
        $access->recordTransition(AccessState::Retired, HealthSignal::ManualRetirement);
    } catch (InvalidArgumentException) {
        // expected — legality gate fired before any persistence
    }

    expect($access->refresh()->state)->toBe(AccessState::New)
        ->and($access->state_changed_at)->toBe($before)
        ->and($access->last_transition_event)->toBeNull();
});

it('blocks Working on stale Telegram freshness even with a health signal', function (): void {
    accessTenant();

    $stale = ProxyAccess::factory()->create([
        'telegram_usable' => true,
        'telegram_checked_at' => now()->subMinutes(30),
        'telegram_fresh_until' => now()->subMinute(),
    ]);
    $stale->recordTransition(AccessState::Testing, HealthSignal::RecheckTriggered);

    $stale->recordTransition(AccessState::Working, HealthSignal::ProbeSucceeded);
})->throws(InvalidArgumentException::class);

it('blocks Working when the Telegram check never happened', function (): void {
    accessTenant();

    $unchecked = ProxyAccess::factory()->create();
    $unchecked->recordTransition(AccessState::Testing, HealthSignal::RecheckTriggered);

    $unchecked->recordTransition(AccessState::Working, HealthSignal::ProbeSucceeded);
})->throws(InvalidArgumentException::class);

it('resolves telegramUsableNow per freshness semantics', function (): void {
    accessTenant();

    $neverChecked = ProxyAccess::factory()->create();
    expect($neverChecked->telegramUsableNow())->toBeNull();

    $fresh = ProxyAccess::factory()->create([
        'telegram_usable' => true,
        'telegram_checked_at' => now(),
        'telegram_fresh_until' => now()->addMinutes(5),
    ]);
    expect($fresh->telegramUsableNow())->toBeTrue();

    $expired = ProxyAccess::factory()->create([
        'telegram_usable' => true,
        'telegram_checked_at' => now()->subHour(),
        'telegram_fresh_until' => now()->subSecond(),
    ]);
    expect($expired->telegramUsableNow())->toBeFalse();

    $failedCheck = ProxyAccess::factory()->create([
        'telegram_usable' => false,
        'telegram_checked_at' => now(),
        'telegram_fresh_until' => now()->addMinutes(5),
    ]);
    expect($failedCheck->telegramUsableNow())->toBeFalse();
});

it('keeps quarantine status independent of AccessState (INV-002 orthogonality)', function (): void {
    accessTenant();

    foreach ([AccessState::New, AccessState::Dead] as $state) {
        $access = ProxyAccess::factory()->state(['state' => $state])->quarantined('auth_failures_threshold')
            ->create();

        expect($access->state)->toBe($state)
            ->and($access->quarantine_status)->toBe(QuarantineStatus::Quarantined)
            ->and($access->quarantine_reason)->toBe('auth_failures_threshold');
    }

    $clean = ProxyAccess::factory()->working()->create();

    expect($clean->state)->toBe(AccessState::Working)
        ->and($clean->quarantine_status)->toBe(QuarantineStatus::None);
});

it('hides accesses of other tenants from queries', function (): void {
    $context = app(TenantContext::class);

    $context->set(accessTenant());
    $mine = ProxyAccess::factory()->create();

    $context->set(accessTenant());

    expect(ProxyAccess::query()->whereKey($mine->id)->exists())->toBeFalse()
        ->and(ProxyAccess::query()->count())->toBe(0);
});

it('throws on queries and creates without a resolved tenant instead of falling back to unscoped', function (): void {
    app(TenantContext::class)->forget();

    expect(fn () => ProxyAccess::query()->get())->toThrow(TenantNotResolvedException::class);

    app(TenantContext::class)->forget();
    $endpointId = (function (): string {
        app(TenantContext::class)->set(createAccessTenantUser()->id);
        $endpointId = ProxyEndpoint::factory()->create()->id;
        app(TenantContext::class)->forget();

        return $endpointId;
    })();

    expect(fn (): ProxyAccess => ProxyAccess::factory()->create(['endpoint_id' => $endpointId]))
        ->toThrow(TenantNotResolvedException::class);
});

it('marks a credential-free access without a credential fingerprint in its hash input', function (): void {
    accessTenant();

    $withCredential = ProxyAccess::factory()->create();
    $free = ProxyAccess::factory()->credentialFree()->for($withCredential->endpoint, 'endpoint')->create();

    expect($free->credential_id)->toBeNull()
        ->and($free->credentialFingerprint())->toBeNull()
        ->and($free->access_identity_hash)
        ->toBe(ProxyAccess::identityHash($free->endpointIdentity(), null))
        ->not->toBe($withCredential->access_identity_hash);
});
