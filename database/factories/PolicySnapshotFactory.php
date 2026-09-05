<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Database\Factories;

use BAGArt\ProxyOperations\Domain\Probe\ProbeProfile;
use BAGArt\ProxyOperations\Domain\Snapshot\AuditPolicySnapshot;
use BAGArt\ProxyOperations\Models\PolicySnapshot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PolicySnapshot>
 */
final class PolicySnapshotFactory extends Factory
{
    protected $model = PolicySnapshot::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $dto = self::dto();

        return [
            'policy_version' => $dto->policyVersion,
            'snapshot' => $dto->jsonSerialize(),
        ];
    }

    /**
     * A minimal valid AuditPolicySnapshot; the row is its serialized form.
     */
    public static function dto(): AuditPolicySnapshot
    {
        return new AuditPolicySnapshot(
            id: 'psnap_'.bin2hex(random_bytes(8)),
            policyVersion: 1,
            frozenAt: now()->toIso8601String(),
            probeProfileMapping: [
                'manual' => ProbeProfile::Standard,
                'scheduled' => ProbeProfile::Light,
            ],
            healthThresholds: [
                'working_after_successes' => 3,
                'dead_after_failures' => 5,
            ],
            quarantineRules: [
                'AUTH_FAILURE' => 2,
            ],
        );
    }

    public function fromDto(AuditPolicySnapshot $snapshot): static
    {
        return $this->state(fn (): array => [
            'policy_version' => $snapshot->policyVersion,
            'snapshot' => $snapshot->jsonSerialize(),
        ]);
    }
}
