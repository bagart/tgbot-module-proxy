<?php

declare(strict_types=1);

use BAGArt\ProxyOperations\Domain\Evidence\EvidenceType;
use BAGArt\ProxyOperations\Domain\Evidence\FreshnessAwareEligibilityPolicy;
use BAGArt\ProxyOperations\Domain\Evidence\VerifiedEligibilityCheck;
use BAGArt\ProxyOperations\Domain\Identity\ProxyProtocol;

beforeEach(function (): void {
    $this->now = new DateTimeImmutable('2026-08-26T12:00:00+00:00');
    $this->policy = new FreshnessAwareEligibilityPolicy(telegramFreshnessSeconds: 3600);
});

function mtprotoCheck(
    array $satisfied,
    bool $usable = true,
    ?DateTimeImmutable $checkedAt = new DateTimeImmutable('2026-08-26T11:30:00+00:00'),
    DateTimeImmutable $now = new DateTimeImmutable('2026-08-26T12:00:00+00:00'),
): VerifiedEligibilityCheck {
    return new VerifiedEligibilityCheck(
        protocol: ProxyProtocol::Mtproto,
        satisfiedDimensions: $satisfied,
        lastTelegramCheckUsable: $usable,
        telegramCheckedAt: $checkedAt,
        now: $now,
    );
}

it('admits MTProto with required TCP + Telegram evidence fresh and usable', function (): void {
    expect($this->policy->isEligible(mtprotoCheck([EvidenceType::Tcp, EvidenceType::Telegram])))->toBeTrue();
});

it('blocks MTProto when the usable flag is false even if evidence exists', function (): void {
    expect($this->policy->isEligible(mtprotoCheck([EvidenceType::Tcp, EvidenceType::Telegram], usable: false)))->toBeFalse();
});

it('blocks eligibility when a required dimension is unsatisfied', function (): void {
    expect($this->policy->isEligible(mtprotoCheck([EvidenceType::Tcp])))->toBeFalse();
});

it('blocks MTProto on stale telegram evidence despite a successful last check', function (): void {
    $staleCheckedAt = $this->now->modify('-3601 seconds');

    expect($this->policy->isEligible(mtprotoCheck(
        [EvidenceType::Tcp, EvidenceType::Telegram],
        usable: true,
        checkedAt: $staleCheckedAt,
        now: $this->now,
    )))->toBeFalse();
});

it('treats the exact freshness boundary as stale', function (): void {
    $boundary = $this->now->modify('-3600 seconds');

    expect($this->policy->isEligible(mtprotoCheck(
        [EvidenceType::Tcp, EvidenceType::Telegram],
        checkedAt: $boundary,
        now: $this->now,
    )))->toBeFalse();
});

it('accepts evidence one second inside the freshness window', function (): void {
    $fresh = $this->now->modify('-3599 seconds');

    expect($this->policy->isEligible(mtprotoCheck(
        [EvidenceType::Tcp, EvidenceType::Telegram],
        checkedAt: $fresh,
        now: $this->now,
    )))->toBeTrue();
});

it('blocks MTProto when the telegram check never ran', function (): void {
    expect($this->policy->isEligible(mtprotoCheck([EvidenceType::Tcp, EvidenceType::Telegram], checkedAt: null, now: $this->now)))->toBeFalse();
});

it('admits non-MTProto protocols without telegram evidence (optional dimension)', function (): void {
    $check = new VerifiedEligibilityCheck(
        protocol: ProxyProtocol::Socks5,
        satisfiedDimensions: [EvidenceType::Tcp, EvidenceType::Http, EvidenceType::Judge],
        lastTelegramCheckUsable: false,
        telegramCheckedAt: null,
        now: $this->now,
    );

    expect($this->policy->isEligible($check))->toBeTrue();
});

it('rejects socks5 missing any required dimension', function (array $satisfied): void {
    $check = new VerifiedEligibilityCheck(
        protocol: ProxyProtocol::Socks5,
        satisfiedDimensions: $satisfied,
        lastTelegramCheckUsable: true,
        telegramCheckedAt: $this->now,
        now: $this->now,
    );

    expect($this->policy->isEligible($check))->toBeFalse();
})->with([
    'no tcp' => [[EvidenceType::Http, EvidenceType::Judge]],
    'no http' => [[EvidenceType::Tcp, EvidenceType::Judge]],
    'no judge' => [[EvidenceType::Tcp, EvidenceType::Http]],
]);

it('refuses to construct with a degenerate freshness window', function (): void {
    new FreshnessAwareEligibilityPolicy(telegramFreshnessSeconds: 0);
})->throws(InvalidArgumentException::class);
