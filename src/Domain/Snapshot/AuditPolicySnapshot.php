<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Domain\Snapshot;

use BAGArt\ProxyOperations\Domain\Probe\ProbeProfile;
use JsonSerializable;
use RuntimeException;

/**
 * Immutable copy of the effective audit policy, frozen at job start
 * (plan §11.10 "immutable PolicySnapshot", §11.35 п.7). Referenced by jobs via
 * `policy_snapshot_id`; results stay reproducible even if the live policy has
 * changed since.
 *
 * Separation rule (plan §11.35 п.7): workspace-editable operational settings —
 * retention, quotas, UI flags, export rules — belong to WorkspacePolicy and are
 * NOT snapshotted here. Only what can change the outcome of a job lives in this
 * snapshot: probe profile mapping, lifecycle thresholds and quarantine rules.
 */
final readonly class AuditPolicySnapshot implements JsonSerializable
{
    public const int SCHEMA_VERSION = 1;

    /**
     * @param  string  $id  Stable policy snapshot id (`policy_snapshot_id` on AuditTask).
     * @param  int  $policyVersion  Monotonic version of the effective policy.
     * @param  string  $frozenAt  ISO 8601 timestamp when the copy was frozen.
     * @param  array<string, ProbeProfile>  $probeProfileMapping  Audit trigger (manual, scheduled, import, feed, lazy_selection, recovery, tg_check) → probe profile.
     * @param  array<string, int|float>  $healthThresholds  Named lifecycle/health thresholds (e.g. working_after_successes, dead_after_failures).
     * @param  array<string, int>  $quarantineRules  Failure code → consecutive-failure count that triggers quarantine.
     */
    public function __construct(
        public readonly string $id,
        public readonly int $policyVersion,
        public readonly string $frozenAt,
        public readonly array $probeProfileMapping,
        public readonly array $healthThresholds,
        public readonly array $quarantineRules,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'policyVersion' => $this->policyVersion,
            'frozenAt' => $this->frozenAt,
            'probeProfileMapping' => array_map(
                static fn (ProbeProfile $profile): string => $profile->value,
                $this->probeProfileMapping,
            ),
            'healthThresholds' => $this->healthThresholds,
            'quarantineRules' => $this->quarantineRules,
            'schemaVersion' => self::SCHEMA_VERSION,
        ];
    }

    /**
     * @param  array<string,mixed>  $data
     *
     * @throws RuntimeException If the format is not recognized or the version field is missing.
     */
    public static function fromJson(array $data): self
    {
        if (! array_key_exists('policyVersion', $data)) {
            throw new RuntimeException('AuditPolicySnapshot payload is missing the mandatory policyVersion field.');
        }

        return match ($data['schemaVersion'] ?? self::SCHEMA_VERSION) {
            self::SCHEMA_VERSION => self::fromJsonV1($data),
            default => throw new RuntimeException('Unsupported AuditPolicySnapshot schemaVersion: '.var_export($data['schemaVersion'], true)),
        };
    }

    /**
     * @param  array<string,mixed>  $data
     */
    private static function fromJsonV1(array $data): self
    {
        return new self(
            id: (string) $data['id'],
            policyVersion: (int) $data['policyVersion'],
            frozenAt: (string) $data['frozenAt'],
            probeProfileMapping: array_map(
                static fn (string $profile): ProbeProfile => ProbeProfile::from($profile),
                (array) ($data['probeProfileMapping'] ?? []),
            ),
            healthThresholds: (array) ($data['healthThresholds'] ?? []),
            quarantineRules: array_map(intval(...), (array) ($data['quarantineRules'] ?? [])),
        );
    }
}
