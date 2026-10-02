<?php

declare(strict_types=1);

use BAGArt\ProxyOperations\Domain\Failure\FailureCode;
use BAGArt\ProxyOperations\Domain\Lifecycle\AccessState;
use BAGArt\ProxyOperations\Domain\Lifecycle\AccessStateMachine;
use BAGArt\ProxyOperations\Domain\Lifecycle\CauseKind;
use BAGArt\ProxyOperations\Domain\Lifecycle\HealthSignal;
use BAGArt\ProxyOperations\Domain\Lifecycle\LifecycleEvent;
use BAGArt\ProxyOperations\Domain\Lifecycle\TransitionRule;

beforeEach(fn (): object => $this->machine = new AccessStateMachine());

it('defines exactly the plan §11.6 states', function (): void {
    expect(array_map(fn (AccessState $s): string => $s->value, AccessState::cases()))->toBe([
        'new',
        'testing',
        'working',
        'degraded',
        'failing',
        'dead',
        'retired',
    ]);
});

it('accepts every legal transition with a matching cause and emits an event', function (
    AccessState $from,
    AccessState $to,
    FailureCode|HealthSignal $cause,
    ?FailureCode $expectedReason,
): void {
    $event = $this->machine->transition($from, $to, $cause);

    expect($event)->toBeInstanceOf(LifecycleEvent::class)
        ->and($event->from)->toBe($from)
        ->and($event->to)->toBe($to)
        ->and($event->reason)->toBe($expectedReason)
        ->and($event->occurredAt)->toBeInstanceOf(DateTimeImmutable::class);
})->with([
    'new → testing' => [AccessState::New, AccessState::Testing, HealthSignal::RecheckTriggered, null],
    'testing → working' => [AccessState::Testing, AccessState::Working, HealthSignal::ProbeSucceeded, null],
    'testing → degraded' => [AccessState::Testing, AccessState::Degraded, FailureCode::TcpTimeout, FailureCode::TcpTimeout],
    'testing → failing' => [AccessState::Testing, AccessState::Failing, FailureCode::AuthFailure, FailureCode::AuthFailure],
    'testing → dead' => [AccessState::Testing, AccessState::Dead, FailureCode::TcpRefused, FailureCode::TcpRefused],
    'working → testing (recheck)' => [AccessState::Working, AccessState::Testing, HealthSignal::ManualRecheck, null],
    'working → degraded' => [AccessState::Working, AccessState::Degraded, FailureCode::Target5xx, FailureCode::Target5xx],
    'degraded → testing (recheck)' => [AccessState::Degraded, AccessState::Testing, HealthSignal::RecheckTriggered, null],
    'degraded → working (recovery)' => [AccessState::Degraded, AccessState::Working, HealthSignal::HealthRecovered, null],
    'degraded → failing' => [AccessState::Degraded, AccessState::Failing, FailureCode::TlsFailure, FailureCode::TlsFailure],
    'failing → testing (recheck)' => [AccessState::Failing, AccessState::Testing, HealthSignal::RecheckTriggered, null],
    'failing → degraded (partial recovery)' => [AccessState::Failing, AccessState::Degraded, HealthSignal::HealthRecovered, null],
    'failing → dead' => [AccessState::Failing, AccessState::Dead, FailureCode::TcpTimeout, FailureCode::TcpTimeout],
    'dead → testing (full re-test)' => [AccessState::Dead, AccessState::Testing, HealthSignal::RecheckTriggered, null],
    'dead → retired' => [AccessState::Dead, AccessState::Retired, HealthSignal::ManualRetirement, null],
]);

it('rejects every transition outside the plan §11.6 graph regardless of cause', function (): void {
    foreach (AccessState::cases() as $from) {
        foreach (AccessState::cases() as $to) {
            if ($this->machine->isAllowed($from, $to)) {
                continue;
            }

            foreach ([FailureCode::TcpTimeout, HealthSignal::ProbeSucceeded] as $cause) {
                expect(fn (): LifecycleEvent => $this->machine->transition($from, $to, $cause))
                    ->toThrow(InvalidArgumentException::class);
            }
        }
    }
});

it('rejects a cause whose polarity contradicts the rule direction', function (): void {
    expect(fn (): LifecycleEvent => $this->machine->transition(AccessState::Working, AccessState::Degraded, HealthSignal::ProbeSucceeded))
        ->toThrow(InvalidArgumentException::class);

    expect(fn (): LifecycleEvent => $this->machine->transition(AccessState::Degraded, AccessState::Working, FailureCode::TcpRefused))
        ->toThrow(InvalidArgumentException::class);
});

it('keeps retired terminal', function (): void {
    expect($this->machine->allowedTargets(AccessState::Retired))->toBe([]);
});

it('exposes rules consistent with the allowed-target map', function (): void {
    $rules = $this->machine->rules();

    expect($rules)->each->toBeInstanceOf(TransitionRule::class);

    foreach ($rules as $rule) {
        expect($rule->causeKind)->toBeIn([CauseKind::FailureCode, CauseKind::HealthSignal])
            ->and($rule->accepts($rule->causeKind === CauseKind::FailureCode ? FailureCode::TcpTimeout : HealthSignal::ProbeSucceeded))->toBeTrue();
    }
});

it('round-trips a lifecycle event through JSON', function (): void {
    $event = new LifecycleEvent(
        from: AccessState::Working,
        to: AccessState::Failing,
        reason: FailureCode::AuthFailure,
        occurredAt: new DateTimeImmutable('2026-08-26T12:00:00+00:00'),
    );

    $restored = LifecycleEvent::fromJson($event->jsonSerialize());

    expect($restored)->toEqual($event)
        ->and($event->jsonSerialize()['schemaVersion'])->toBe(LifecycleEvent::SCHEMA_VERSION);
});

it('serializes signal-driven events with a null reason', function (): void {
    $event = new LifecycleEvent(
        from: AccessState::New,
        to: AccessState::Testing,
        reason: null,
        occurredAt: new DateTimeImmutable('2026-08-26T12:00:00+00:00'),
    );

    expect(LifecycleEvent::fromJson($event->jsonSerialize()))->toEqual($event);
});

it('rejects unknown lifecycle event schema versions', function (): void {
    expect(fn (): LifecycleEvent => LifecycleEvent::fromJson(['schemaVersion' => 99]))
        ->toThrow(RuntimeException::class);
});
