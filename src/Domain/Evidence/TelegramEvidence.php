<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Domain\Evidence;

use BAGArt\ProxyOperations\Domain\Failure\FailureCode;
use DateTimeImmutable;

/**
 * Raw Telegram DC connectivity observation (Telegram dimension).
 *
 * `telegram_usable` is NOT stored here — it is derived from this evidence plus
 * freshness policy (plan §11.35 item 10). The DC set version ties observations
 * to a TelegramDcSet snapshot (§11.35 item 13).
 *
 * @see https://core.telegram.org/bots/api
 */
final readonly class TelegramEvidence implements DimensionEvidence
{
    /**
     * @param  list<int>  $reachableDcIds  DC ids that answered within limits.
     * @param  list<int>  $attemptedDcIds  All DC ids probed in this round.
     * @param  string  $dcSetVersion  Version of the TelegramDcSet used.
     */
    public function __construct(
        public array $reachableDcIds,
        public array $attemptedDcIds,
        public string $dcSetVersion,
        public ?float $medianRttMs,
        public ?FailureCode $failureCode,
        public DateTimeImmutable $measuredAt,
    ) {}

    public function type(): EvidenceType
    {
        return EvidenceType::Telegram;
    }

    public function measuredAt(): DateTimeImmutable
    {
        return $this->measuredAt;
    }
}
