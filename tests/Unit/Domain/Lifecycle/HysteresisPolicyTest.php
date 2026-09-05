<?php

declare(strict_types=1);

use BAGArt\ProxyOperations\Domain\Lifecycle\AccessState;
use BAGArt\ProxyOperations\Domain\Lifecycle\HysteresisPolicy;

beforeEach(fn (): object => $this->policy = new HysteresisPolicy(
    consecutiveFailuresToDegrade: 2,
    consecutiveFailuresToFailing: 5,
    consecutiveFailuresToDeclareDead: 10,
    consecutiveSuccessesToLeaveFailing: 2,
    consecutiveSuccessesToLeaveDegraded: 3,
    minSecondsBetweenTransitions: 60,
));

it('holds working until the degrade threshold is reached', function (int $failures, ?AccessState $target): void {
    expect($this->policy->escalationTarget(AccessState::Working, $failures))->toBe($target);
})->with([
    'below threshold' => [1, null],
    'at threshold' => [2, AccessState::Degraded],
]);

it('escalates one ladder step at a time', function (): void {
    expect($this->policy->escalationTarget(AccessState::Degraded, 4))->toBeNull()
        ->and($this->policy->escalationTarget(AccessState::Degraded, 5))->toBe(AccessState::Failing)
        ->and($this->policy->escalationTarget(AccessState::Failing, 9))->toBeNull()
        ->and($this->policy->escalationTarget(AccessState::Failing, 10))->toBe(AccessState::Dead);
});

it('never escalates terminal or classification states', function (AccessState $state): void {
    expect($this->policy->escalationTarget($state, 999))->toBeNull();
})->with([
    AccessState::New,
    AccessState::Testing,
    AccessState::Dead,
    AccessState::Retired,
]);

it('recovers through the ladder only after sustained successes', function (): void {
    expect($this->policy->recoveryTarget(AccessState::Failing, 1))->toBeNull()
        ->and($this->policy->recoveryTarget(AccessState::Failing, 2))->toBe(AccessState::Degraded)
        ->and($this->policy->recoveryTarget(AccessState::Degraded, 2))->toBeNull()
        ->and($this->policy->recoveryTarget(AccessState::Degraded, 3))->toBe(AccessState::Working);
});

it('never recovers terminal states directly', function (AccessState $state): void {
    expect($this->policy->recoveryTarget($state, 999))->toBeNull()
        ->and($this->policy->recoveryTarget($state, 0))->toBeNull();
})->with([
    AccessState::New,
    AccessState::Testing,
    AccessState::Working,
    AccessState::Dead,
    AccessState::Retired,
]);

it('guards against flapping inside the dwell window', function (): void {
    $enteredAt = new DateTimeImmutable('2026-08-26T12:00:00+00:00');

    expect($this->policy->allowsFlip($enteredAt, $enteredAt->modify('+59 seconds')))->toBeFalse()
        ->and($this->policy->allowsFlip($enteredAt, $enteredAt->modify('+60 seconds')))->toBeTrue();
});

it('rejects non-ascending escalation thresholds', function (): void {
    new HysteresisPolicy(consecutiveFailuresToDegrade: 5, consecutiveFailuresToFailing: 2);
})->throws(InvalidArgumentException::class);

it('rejects counters below one', function (): void {
    new HysteresisPolicy(consecutiveFailuresToDegrade: 0);
})->throws(InvalidArgumentException::class);

it('rejects negative dwell windows', function (): void {
    new HysteresisPolicy(minSecondsBetweenTransitions: -1);
})->throws(InvalidArgumentException::class);
