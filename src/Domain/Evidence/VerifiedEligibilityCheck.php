<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Domain\Evidence;

use BAGArt\ProxyOperations\Domain\Identity\ProxyProtocol;
use DateTimeImmutable;

/**
 * Inputs of one verified-eligibility decision (plan §11.35 item 11).
 *
 * Carries which dimensions the latest audit satisfied plus the raw Telegram
 * freshness facts; the policy interprets them per protocol.
 */
final readonly class VerifiedEligibilityCheck
{
    /**
     * @param  list<EvidenceType>  $satisfiedDimensions  Evidence types whose latest
     *                                                   measurement passed health evaluation.
     * @param  bool  $lastTelegramCheckUsable  Usable flag of the last Telegram check
     *                                         (raw result, before freshness interpretation).
     * @param  DateTimeImmutable|null  $telegramCheckedAt  When the last Telegram check ran.
     * @param  DateTimeImmutable  $now  Decision timestamp (explicit for testability).
     */
    public function __construct(
        public ProxyProtocol $protocol,
        public array $satisfiedDimensions,
        public bool $lastTelegramCheckUsable,
        public ?DateTimeImmutable $telegramCheckedAt,
        public DateTimeImmutable $now,
    ) {
    }

    public function satisfies(EvidenceType $type): bool
    {
        return in_array($type, $this->satisfiedDimensions, true);
    }
}
