<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Domain\Evidence;

use InvalidArgumentException;

/**
 * Default VerifiedEligibilityPolicy: all REQUIRED dimensions must be satisfied,
 * and a stale Telegram flag can never count as usable (plan §11.35 item 10 —
 * "протухший флаг не выдаётся как true").
 *
 * Freshness window and decision time are explicit values (no clock access), so
 * staleness behavior is testable without clock mocks.
 */
final readonly class FreshnessAwareEligibilityPolicy implements VerifiedEligibilityPolicy
{
    public function __construct(
        private EvidenceApplicability $applicability = new EvidenceApplicability(),
        public int $telegramFreshnessSeconds = 21600,
    ) {
        if ($this->telegramFreshnessSeconds < 1) {
            throw new InvalidArgumentException('Telegram freshness window must be >= 1 second');
        }
    }

    public function isEligible(VerifiedEligibilityCheck $check): bool
    {
        foreach ($this->applicability->requiredFor($check->protocol) as $type) {
            if (! $check->satisfies($type)) {
                return false;
            }
        }

        if ($this->applicability->for($check->protocol, EvidenceType::Telegram) !== Applicability::Required) {
            return true;
        }

        if (! $check->lastTelegramCheckUsable || $check->telegramCheckedAt === null) {
            return false;
        }

        return $check->now->getTimestamp() - $check->telegramCheckedAt->getTimestamp() < $this->telegramFreshnessSeconds;
    }
}
