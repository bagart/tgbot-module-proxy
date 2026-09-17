<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Audit\Consumers;

use BAGArt\ProxyOperations\Domain\Evidence\EvidenceType;
use BAGArt\ProxyOperations\Domain\Evidence\VerifiedEligibilityCheck;
use BAGArt\ProxyOperations\Domain\Evidence\VerifiedEligibilityPolicy;
use BAGArt\ProxyOperations\Domain\Identity\ProxyProtocol;
use BAGArt\ProxyOperations\Models\ProxyAccess;
use BAGArt\ProxyOperations\Models\VerifiedProxyProjection;

/**
 * Projects verified proxy eligibility on AuditCompleted events (plan §11.35 items 4,11).
 * Upserts or deletes the projection based on the eligibility policy.
 */
final class VerifiedProxyProjector
{
    public function __construct(
        private VerifiedEligibilityPolicy $eligibilityPolicy,
    ) {}

    public function project(ProxyAccess $access): void
    {
        $endpoint = $access->endpoint;
        $health = $access->health()->latest('checked_at')->first();
        $protocol = $endpoint?->protocol ?? ProxyProtocol::Socks5;

        $check = new VerifiedEligibilityCheck(
            protocol: is_string($protocol) ? ProxyProtocol::from($protocol) : $protocol,
            satisfiedDimensions: $this->extractSatisfiedDimensions($health),
            lastTelegramCheckUsable: $access->telegram_usable ?? false,
            telegramCheckedAt: $access->telegram_checked_at,
            now: new \DateTimeImmutable,
        );

        if ($this->eligibilityPolicy->isEligible($check)) {
            $this->upsert($access, $endpoint, $health);
        } else {
            $this->remove($access);
        }
    }

    private function upsert(ProxyAccess $access, ?ProxyEndpoint $endpoint, ?ProxyHealth $health): void
    {
        VerifiedProxyProjection::updateOrCreate(
            [
                'tenant_id' => $access->tenant_id,
                'access_id' => $access->id,
            ],
            [
                'endpoint_id' => $access->endpoint_id,
                'protocol' => $endpoint?->protocol ?? '',
                'credential_projection' => ['masked' => true],
                'capabilities_projection' => $this->extractCapabilities($access),
                'health_projection' => $this->extractHealth($health),
                'telegram_usable' => $access->telegram_usable,
                'verification_policy_version' => 'v1',
                'verified_at' => now(),
                'last_checked_at' => $access->last_checked_at,
            ],
        );
    }

    private function remove(ProxyAccess $access): void
    {
        VerifiedProxyProjection::where('tenant_id', $access->tenant_id)
            ->where('access_id', $access->id)
            ->delete();
    }

    private function extractSatisfiedDimensions(?ProxyHealth $health): array
    {
        if ($health === null) {
            return [];
        }

        $dimensions = [];
        foreach (['tcp', 'http', 'tls', 'dns', 'udp', 'judge', 'telegram', 'bandwidth'] as $dim) {
            $signal = $health->getAttribute("{$dim}_signal") ?? null;
            if ($signal === 'pass' || $signal === 'ok') {
                $dimensions[] = EvidenceType::from($dim);
            }
        }

        return $dimensions;
    }

    private function extractCapabilities(ProxyAccess $access): array
    {
        return [
            'state' => $access->state->value,
            'testability' => $access->testability_status->value,
            'quarantine' => $access->quarantine_status->value,
        ];
    }

    private function extractHealth(?ProxyHealth $health): array
    {
        if ($health === null) {
            return ['score' => null];
        }

        return [
            'score' => $health->health_score,
            'dimensions' => $health->dimension_signals ?? [],
        ];
    }
}
