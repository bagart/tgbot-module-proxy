<?php

declare(strict_types=1);

use App\Models\User;
use BAGArt\ProxyOperations\Audit\DimensionalHealthEvaluator;
use BAGArt\ProxyOperations\Domain\Evidence\EvidenceType;
use BAGArt\ProxyOperations\Domain\Evidence\HttpEvidence;
use BAGArt\ProxyOperations\Domain\Evidence\JudgeEvidence;
use BAGArt\ProxyOperations\Domain\Evidence\TcpEvidence;
use BAGArt\ProxyOperations\Domain\Evidence\TelegramEvidence;
use BAGArt\ProxyOperations\Domain\Failure\FailureCode;
use BAGArt\ProxyOperations\Domain\Lifecycle\AccessState;
use BAGArt\ProxyOperations\Domain\Lifecycle\HealthSignal;
use BAGArt\ProxyOperations\Domain\Lifecycle\HysteresisPolicy;
use BAGArt\ProxyOperations\Models\ProxyAccess;
use BAGArt\ProxyOperations\Models\ProxyHealth;
use BAGArt\ProxyOperations\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    $this->tenantId = User::factory()->create()->id;
    app(TenantContext::class)->set($this->tenantId);

    $this->evaluator = new DimensionalHealthEvaluator(
        hysteresis: new HysteresisPolicy(minSecondsBetweenTransitions: 0),
        telegramFreshnessSeconds: 21600,
        healthFormulaVersion: 'dimensional-v1',
    );
});

function passingEvidence(): array
{
    return [
        new TcpEvidence(true, 42, null, now()),
        new HttpEvidence(true, 200, 1284, null, 610.0, true, false, false, false, false, false, null, now()),
        new JudgeEvidence('judge-1', true, true, 120.0, null, now()),
        new TelegramEvidence([2, 4], [2, 4], 'v1', 55.0, null, now()),
    ];
}

function failingHttpEvidence(): HttpEvidence
{
    return new HttpEvidence(false, null, null, null, null, false, false, false, false, false, false, FailureCode::Target5xx, now());
}

/**
 * Full liveness+transport+judge+telegram pass on an UNKNOWN (new) access:
 * the first full classification walks New → Testing → Working through the
 * canonical state machine — two legal single-step transitions, never a jump.
 */
it('promotes a new access to working on a full evidence pass', function (): void {
    $access = ProxyAccess::factory()->create();

    $evaluation = $this->evaluator->evaluate($access, passingEvidence());

    $access->refresh();

    expect($access->state)->toBe(AccessState::Working)
        ->and($evaluation->stateBefore)->toBe(AccessState::New)
        ->and($evaluation->stateAfter)->toBe(AccessState::Working)
        ->and($evaluation->signal)->toBe(HealthSignal::ProbeSucceeded)
        ->and($evaluation->lifecycleEvent?->to)->toBe(AccessState::Working)
        ->and($evaluation->healthFormulaVersion)->toBe('dimensional-v1')
        ->and($evaluation->dimensionSignal(EvidenceType::Tcp))->toBe('pass')
        ->and($evaluation->dimensionSignal(EvidenceType::Dns))->toBe('unknown');
});

/**
 * §11.6 / §11.35 п.9: evidence is not a linear ladder. A DEAD access with a
 * lone passing TCP check (required dimensions unknown → insufficient) never
 * jumps to WORKING; resurrection is a full re-test, not a partial pass.
 */
it('keeps a dead access dead when only the tcp dimension passes', function (): void {
    $access = ProxyAccess::factory()->dead()->create();

    $evaluation = $this->evaluator->evaluate($access, [
        new TcpEvidence(true, 30, null, now()),
    ]);

    $access->refresh();

    expect($access->state)->toBe(AccessState::Dead)
        ->and($evaluation->stateAfter)->toBeNull()
        ->and($evaluation->signal)->toBeNull()
        ->and($evaluation->dimensionSignal(EvidenceType::Http))->toBe('unknown');
});

/**
 * §11.35 п.9: MTProto needs TCP + Telegram only — HTTP/UDP/DNS/Judge are
 * NOT_APPLICABLE and never block the WORKING relay.
 */
it('promotes an mtproto access to working without http evidence', function (): void {
    $access = ProxyAccess::factory()->create();
    $access->endpoint()->firstOrFail()->update(['protocol' => 'mtproto', 'port' => 443]);
    $access->refresh();

    $evaluation = $this->evaluator->evaluate($access, [
        new TcpEvidence(true, 25, null, now()),
        new TelegramEvidence([2], [2], 'v1', 40.0, null, now()),
    ]);

    $access->refresh();

    expect($access->state)->toBe(AccessState::Working)
        ->and($evaluation->stateAfter)->toBe(AccessState::Working)
        ->and($evaluation->dimensionSignal(EvidenceType::Http))->toBe('not_applicable')
        ->and($evaluation->dimensionSignal(EvidenceType::Udp))->toBe('not_applicable')
        ->and($evaluation->dimensionSignal(EvidenceType::Judge))->toBe('not_applicable');
});

/**
 * §11.29 IMPROVE#11: a single failing signal below the degradation threshold
 * holds the state; N consecutive agreeing signals fire the transition. The
 * counter persists on the access row, so it survives process restarts.
 */
it('fires the working to degraded transition only after consecutive agreeing failures', function (): void {
    $access = ProxyAccess::factory()->working()->create();

    $first = $this->evaluator->evaluate($access, [failingHttpEvidence()]);
    expect($first->stateAfter)->toBeNull()
        ->and($access->refresh()->state)->toBe(AccessState::Working)
        ->and($access->consecutive_failures)->toBe(1);

    $second = $this->evaluator->evaluate($access, [failingHttpEvidence()]);

    expect($second->stateAfter)->toBe(AccessState::Degraded)
        ->and($access->refresh()->state)->toBe(AccessState::Degraded)
        ->and($second->failureCode)->toBe(FailureCode::Target5xx)
        ->and($second->lifecycleEvent?->reason)->toBe(FailureCode::Target5xx);
});

it('recovers a degraded access to working only after the configured success streak', function (): void {
    // Degraded with a still-fresh Telegram check: WORKING promotion is
    // otherwise blocked by the §11.35 п.10 freshness gate.
    $access = ProxyAccess::factory()->working()->create();
    $access->forceFill(['state' => AccessState::Degraded->value, 'consecutive_failures' => 3])->save();

    $evidence = passingEvidence();

    $this->evaluator->evaluate($access, $evidence);
    $this->evaluator->evaluate($access, $evidence);

    expect($access->refresh()->state)->toBe(AccessState::Degraded)
        ->and($access->consecutive_successes)->toBe(2);

    $recovered = $this->evaluator->evaluate($access, $evidence);

    expect($recovered->stateAfter)->toBe(AccessState::Working)
        ->and($recovered->signal)->toBe(HealthSignal::ProbeSucceeded)
        ->and($access->refresh()->state)->toBe(AccessState::Working)
        ->and($access->consecutive_successes)->toBe(0);
});

it('does not emit a duplicate transition when the same passing evidence repeats', function (): void {
    $access = ProxyAccess::factory()->create();
    $evidence = passingEvidence();

    $first = $this->evaluator->evaluate($access, $evidence);
    $second = $this->evaluator->evaluate($access, $evidence);

    expect($first->stateAfter)->toBe(AccessState::Working)
        ->and($second->stateAfter)->toBeNull()
        ->and($second->stateBefore)->toBe(AccessState::Working)
        ->and($access->refresh()->state)->toBe(AccessState::Working)
        ->and($access->lastTransitionEvent()?->to)->toBe(AccessState::Working);
});

it('writes one access-scoped health row with the formula version', function (): void {
    $access = ProxyAccess::factory()->create();

    $this->evaluator->evaluate($access, passingEvidence());
    $this->evaluator->evaluate($access, passingEvidence());

    $rows = ProxyHealth::query()->get();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->tenant_id)->toBe($this->tenantId)
        ->and($rows[0]->access_id)->toBe($access->id)
        ->and($rows[0]->health_formula_version)->toBe('dimensional-v1')
        // Formula v1: share of the 8 applicable Socks5 dimensions passed
        // (tcp, http, judge, telegram) = 50.
        ->and($rows[0]->health_score)->toBeInt()->toBe(50)
        ->and($rows[0]->computed_at)->not->toBeNull();
});

/**
 * INV-001/INV-006: health rows are keyed by (tenant, access); a foreign
 * tenant context can neither see nor reuse another tenant's health row.
 */
it('scopes health rows to the tenant and access', function (): void {
    $access = ProxyAccess::factory()->create();
    $this->evaluator->evaluate($access, passingEvidence());

    $otherTenantId = User::factory()->create()->id;
    app(TenantContext::class)->set($otherTenantId);

    expect(ProxyHealth::query()->count())->toBe(0);

    $foreignAccess = ProxyAccess::factory()->create();

    $this->evaluator->evaluate($foreignAccess, passingEvidence());

    $rows = ProxyHealth::query()->get();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->tenant_id)->toBe($otherTenantId)
        ->and($rows[0]->access_id)->not->toBe($access->id);
});

/**
 * §11.35 п.10: telegram_usable is derived from the last check plus the
 * freshness policy — an expired flag is never reported true, even when the
 * last check succeeded.
 */
it('derives telegram usability as false after the freshness window expires', function (): void {
    $access = ProxyAccess::factory()->create();

    $this->evaluator->evaluate($access, passingEvidence());

    expect($access->telegram_connectivity)->toBeTrue()
        ->and($access->telegram_usable)->toBeTrue()
        ->and($access->telegram_checked_at)->not->toBeNull()
        ->and($access->telegram_fresh_until)->toBeInstanceOf(CarbonImmutable::class)
        ->and($access->telegram_evidence_version)->toBe('tg-dc:v1')
        ->and($access->telegramUsableNow())->toBeTrue();

    // Simulate freshness expiry without clock mocks.
    $access->forceFill(['telegram_fresh_until' => now()->subSecond()])->save();

    expect($access->telegramUsableNow())->toBeFalse();

    // Re-evaluation with the same check result measured past the freshness
    // window: telegram_usable keeps the raw last-check fact, but the derived
    // flag is false — an expired flag is never reported usable.
    $stale = new TelegramEvidence([2, 4], [2, 4], 'v1', 55.0, null, now()->subSeconds(21601));
    $evaluation = $this->evaluator->evaluate($access, [
        new TcpEvidence(true, 42, null, now()),
        new HttpEvidence(true, 200, 1284, null, 610.0, true, false, false, false, false, false, null, now()),
        new JudgeEvidence('judge-1', true, true, 120.0, null, now()),
        $stale,
    ]);

    expect($evaluation->stateAfter)->toBeNull()
        ->and($access->refresh()->telegram_usable)->toBeTrue()
        ->and($access->telegramUsableNow())->toBeFalse();
});

/**
 * §11.6: conflicting evidence across dimensions — any explicit dimension
 * failure makes the batch negative regardless of other passing dimensions.
 * From New the escalation ladder has no negative edge, so the state holds
 * while the failure counter (hysteresis input) accumulates.
 */
it('treats conflicting dimension evidence as a negative batch and holds the state', function (): void {
    $access = ProxyAccess::factory()->create();

    $evaluation = $this->evaluator->evaluate($access, [
        new TcpEvidence(true, 30, null, now()),
        failingHttpEvidence(),
    ]);

    $access->refresh();

    expect($evaluation->signal)->toBeNull()
        ->and($evaluation->stateAfter)->toBeNull()
        ->and($access->state)->toBe(AccessState::New)
        ->and($access->consecutive_failures)->toBe(1)
        ->and($access->consecutive_successes)->toBe(0);
});
