<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Domain\Pool;

use BAGArt\ProxyOperations\Domain\Identity\ProxyProtocol;
use BAGArt\ProxyOperations\Domain\Lifecycle\AccessState;
use BAGArt\ProxyOperations\Models\ProxyAccess;
use BAGArt\ProxyOperations\Models\ProxyHealth;

/**
 * Flat projection of one access for predicate evaluation (plan §11.25).
 * Pure function of the access + its health row — no queries, no
 * interpretation: values are read as stored.
 */
final readonly class PoolCandidateView
{
    public function __construct(
        public readonly string $accessId,
        public readonly AccessState $state,
        public readonly ProxyProtocol $protocol,
        public readonly ?int $healthScore,
        public readonly ?bool $telegramUsableNow,
    ) {}

    public static function fromModels(ProxyAccess $access, ?ProxyHealth $health): self
    {
        $endpoint = $access->endpoint;

        if ($endpoint === null) {
            $endpoint = $access->endpoint()->firstOrFail();
        }

        return new self(
            accessId: $access->id,
            state: $access->state,
            protocol: $endpoint->protocol,
            healthScore: $health?->health_score,
            telegramUsableNow: $access->telegramUsableNow(),
        );
    }
}
