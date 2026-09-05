<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Audit;

use BAGArt\ProxyOperations\Domain\Failure\ProxyFailure;
use BAGArt\ProxyOperations\Domain\Probe\ProbeProfile;
use BAGArt\ProxyOperations\Domain\Probe\ProbeType;
use BAGArt\ProxyOperations\Models\ProxyAuditAttempt;
use BAGArt\ProxyOperations\Models\ProxyAuditJob;
use BAGArt\ProxyOperations\Models\ProxyObservation;
use BAGArt\ProxyOperations\Wire\AuditResultV1;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Append-only ProxyObservation insert guard (plan §§11.7, 11.16, 11.21 —
 * T07 immutability): rows are created once, never updated or deleted
 * (ProxyObservation raises ImmutableRecordException on any mutation attempt).
 * Alongside the raw evidence the writer stores `probe_profile_version` and
 * `policy_snapshot_id` for R6.6 explainability (the policy snapshot the audit
 * ran under). Must run inside the ingestion transaction.
 */
final class ObservationWriter
{
    /**
     * @param  list<\BAGArt\ProxyOperations\Domain\Evidence\DimensionEvidence>  $evidence
     *
     * @throws InvalidArgumentException When the result carries no resolvable access.
     */
    public function write(ProxyAuditJob $job, AuditResultV1 $result, array $evidence): ProxyObservation
    {
        $failures = $result->observations;
        $snapshot = $job->policySnapshot->toDto();
        $profile = $snapshot->probeProfileMapping[$job->trigger->value] ?? ProbeProfile::Standard;

        /** @var list<ProxyFailure> $failures */
        $failurePayload = [];

        foreach (array_values($failures) as $index => $failure) {
            $failurePayload['failure_'.$index] = [
                'code' => $failure->descriptor->code->value,
                'class' => $failure->descriptor->class->value,
                'context' => $failure->context,
            ];
        }

        return ProxyObservation::query()->create([
            'access_id' => $result->accessId,
            'checked_at' => self::checkedAt($evidence),
            'probe_type' => self::probeType($result)->value,
            'probe_profile' => $profile->value,
            'probe_profile_version' => (string) $snapshot->policyVersion,
            'policy_snapshot_id' => $job->policy_snapshot_id,
            'judge_set_version' => null,
            'checker_node_id' => $result->checkerNodeId,
            'checker_region' => null,
            'outcome' => $failures === [] ? 'success' : 'failure',
            'failure_code' => $failures[0]->descriptor->code->value ?? null,
            'failure_class' => $failures[0]->descriptor->class->value ?? null,
            'evidence' => $result->probeData === [] && $failurePayload === []
                ? (object) []
                : array_filter([
                    'probe_data' => $result->probeData === [] ? null : self::stringKeyed($result->probeData),
                    'failures' => $failurePayload === [] ? null : $failurePayload,
                ]),
            'schema_version' => ProxyObservation::SCHEMA_VERSION,
        ]);
    }

    /**
     * The evidence JSON must be fully string-keyed (R6.4 guard): record lists
     * become `sample_N` maps, preserving order.
     *
     * @param  array<string, list<array<string,mixed>>>  $probeData
     * @return array<string, array<string, array<string,mixed>>>
     */
    private static function stringKeyed(array $probeData): array
    {
        $keyed = [];

        foreach ($probeData as $probeType => $records) {
            $keyed[$probeType] = [];

            foreach (array_values(is_array($records) ? $records : []) as $index => $record) {
                $keyed[$probeType]['sample_'.$index] = is_array($record) ? $record : [];
            }
        }

        return $keyed;
    }

    /**
     * Event time of the probe execution (never silently defaulted where raw
     * timestamps exist): the latest measured_at across extracted evidence.
     *
     * @param  list<\BAGArt\ProxyOperations\Domain\Evidence\DimensionEvidence>  $evidence
     */
    private static function checkedAt(array $evidence): Carbon
    {
        $latest = null;

        foreach ($evidence as $item) {
            if ($latest === null || $item->measuredAt() > $latest) {
                $latest = $item->measuredAt();
            }
        }

        return $latest === null
            ? Carbon::now()
            : Carbon::instance($latest);
    }

    /**
     * One observation row per result: the first successful probe type in the
     * probeData map names the row; failures-only results default to the
     * liveness probe.
     */
    private static function probeType(AuditResultV1 $result): ProbeType
    {
        foreach (array_keys($result->probeData) as $probeTypeName) {
            $probeType = ProbeType::tryFrom((string) $probeTypeName);

            if ($probeType !== null) {
                return $probeType;
            }
        }

        return ProbeType::HttpLiveness;
    }
}
