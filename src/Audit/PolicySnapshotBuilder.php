<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Audit;

use BAGArt\ProxyOperations\Domain\Probe\ProbeProfile;
use BAGArt\ProxyOperations\Domain\Snapshot\AuditPolicySnapshot;
use BAGArt\ProxyOperations\Models\AuditTrigger;
use BAGArt\ProxyOperations\Models\PolicySnapshot;
use BAGArt\ProxyOperations\Models\ProxyPolicy;

/**
 * Builds the immutable AuditPolicySnapshot for a job start (plan §§11.18,
 * 11.35 п.7): only what can change the outcome of a job — probe profile
 * mapping, lifecycle thresholds, quarantine rules — is frozen. WorkspacePolicy
 * content (retention, quotas, UI flags, export rules) deliberately never
 * enters the snapshot payload.
 *
 * Content sources: the system defaults from `config('proxy-operations.audit')`
 * plus the per-tenant workspace row (T08) — the workspace record is lazily
 * ensured on each build, but its operational flags stay out of the payload.
 *
 * Versioning: `policy_version` is monotonic per tenant, derived from the
 * latest persisted snapshot row. When the effective content is unchanged
 * (content hash over the outcome-relevant fields), the previous row is reused
 * so identical policies keep the same policy_version and snapshot id.
 */
final class PolicySnapshotBuilder
{
    /**
     * Outcome-relevant content, canonicalized for hashing (sorted keys).
     *
     * @param  array<string, ProbeProfile>  $probeProfileMapping
     * @param  array<string, int|float>  $healthThresholds
     * @param  array<string, int>  $quarantineRules
     */
    private static function contentHash(
        array $probeProfileMapping,
        array $healthThresholds,
        array $quarantineRules,
    ): string {
        return hash('sha256', (string) json_encode([
            'probeProfileMapping' => $probeProfileMapping,
            'healthThresholds' => $healthThresholds,
            'quarantineRules' => $quarantineRules,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * Build — or reuse — the tenant's policy snapshot for the given trigger.
     */
    public function build(AuditTrigger $trigger, string $probeProfile): PolicySnapshot
    {
        // Lazy workspace creation on first audit (§4); WorkspacePolicy fields
        // themselves are intentionally NOT read into the snapshot payload.
        ProxyPolicy::forCurrentTenant();

        $mapping = $this->probeProfileMapping($trigger, $probeProfile);
        $thresholds = $this->healthThresholds();
        $rules = $this->quarantineRules();
        $hash = self::contentHash($mapping, $thresholds, $rules);

        $latest = PolicySnapshot::query()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        if ($latest !== null && self::storedContentHash($latest->toDto()) === $hash) {
            return $latest;
        }

        return PolicySnapshot::fromDto(new AuditPolicySnapshot(
            id: 'psnap_'.bin2hex(random_bytes(8)),
            policyVersion: ($latest->policy_version ?? 0) + 1,
            frozenAt: now()->toIso8601String(),
            probeProfileMapping: $mapping,
            healthThresholds: $thresholds,
            quarantineRules: $rules,
        ));
    }

    /**
     * Trigger → probe profile for the new snapshot: the configured mapping
     * with the requested profile pinned for the current trigger.
     *
     * @return array<string, ProbeProfile>
     */
    private function probeProfileMapping(AuditTrigger $trigger, string $probeProfile): array
    {
        $configured = (array) config('proxy-operations.audit.probe_profile_mapping', []);

        $mapping = [];
        foreach ($configured as $triggerValue => $profileValue) {
            if (is_string($triggerValue) && is_string($profileValue)) {
                $mapping[$triggerValue] = ProbeProfile::from($profileValue);
            }
        }

        // The request pins the profile for its own trigger — a scheduler-side
        // judge-outage downgrade (§11.27) must survive into the snapshot.
        $mapping[$trigger->value] = ProbeProfile::from($probeProfile);

        ksort($mapping);

        return $mapping;
    }

    /** @return array<string, int|float> */
    private function healthThresholds(): array
    {
        $configured = (array) config('proxy-operations.audit.lifecycle_thresholds', []);

        $thresholds = [];
        foreach ($configured as $name => $value) {
            if (is_string($name) && (is_int($value) || is_float($value))) {
                $thresholds[$name] = $value;
            }
        }

        ksort($thresholds);

        return $thresholds;
    }

    /** @return array<string, int> */
    private function quarantineRules(): array
    {
        $configured = (array) config('proxy-operations.audit.quarantine_rules', []);

        $rules = [];
        foreach ($configured as $code => $count) {
            if (is_string($code) && is_int($count)) {
                $rules[$code] = $count;
            }
        }

        ksort($rules);

        return $rules;
    }

    /**
     * Recompute the content hash of a stored snapshot, ignoring the fields
     * that change on every freeze attempt (id, version, frozenAt).
     */
    private static function storedContentHash(AuditPolicySnapshot $snapshot): string
    {
        $mapping = $snapshot->probeProfileMapping;
        ksort($mapping);
        $thresholds = $snapshot->healthThresholds;
        ksort($thresholds);
        $rules = $snapshot->quarantineRules;
        ksort($rules);

        return self::contentHash($mapping, $thresholds, $rules);
    }
}
