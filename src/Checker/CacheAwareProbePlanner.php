<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Checker;

use BAGArt\ProxyOperations\Audit\CachePolicy;
use BAGArt\ProxyOperations\Audit\ProbeCache;
use BAGArt\ProxyOperations\Audit\ProbeCacheEntry;
use BAGArt\ProxyOperations\Audit\ProbeCacheKeyFactory;
use BAGArt\ProxyOperations\Audit\ProbeCacheMetrics;
use BAGArt\ProxyOperations\Domain\Cache\ProbeCacheKeyV3;
use BAGArt\ProxyOperations\Domain\Cache\SharedCacheValue;
use BAGArt\ProxyOperations\Domain\Cache\SharedCacheValueKind;
use BAGArt\ProxyOperations\Domain\Failure\ExecutionFailure;
use BAGArt\ProxyOperations\Domain\Failure\FailureCode;
use BAGArt\ProxyOperations\Domain\Failure\FailureTaxonomy;
use BAGArt\ProxyOperations\Domain\Probe\ProbeType;
use BAGArt\ProxyOperations\Domain\Snapshot\JudgeSetSnapshot;
use BAGArt\ProxyOperations\Tool\ProbeToolResult;
use BAGArt\ProxyOperations\Wire\AuditTaskV1;
use BAGArt\ProxyOperations\Wire\ProbeExecutionSpecV1;
use RuntimeException;

/**
 * Cache-aware wrapper around the probe execution loop (T30; plan §§11.7,
 * 11.14, 11.39 пп.5–6): consults the shared raw-probe cache before any tool
 * dispatch, reconstructs untouched ProbeToolResult DTOs on hit and stores
 * raw evidence on miss. Composition over ProbeExecutor — no inheritance,
 * no domain decisions (INV-014/015 unchanged: cached evidence is exactly
 * what the tool returned).
 *
 * Key layout (see ProbeCacheKeyFactory): one cache entry per
 * (task identity, probe type, semantics base) mapped to a fixed
 * SharedCacheValueKind per probe type.
 */
final class CacheAwareProbePlanner
{
    /** Prefix under which per-probe timings are flattened into the payload. */
    private const string TIMING_PREFIX = 'timing_';

    /** Payload key carrying the judge id of the stored execution. */
    private const string JUDGE_KEY = 'judge_id';

    /**
     * ProbeType → SharedCacheValueKind mapping (plan §11.7 allowlist).
     * ExitIpObservation has no dedicated probe type yet; exit-ip style
     * evidence travels inside http_measurement payloads.
     */
    private const array KIND_BY_PROBE_TYPE = [
        ProbeType::HttpLiveness->value => SharedCacheValueKind::HttpMeasurement,
        ProbeType::LatencySeries->value => SharedCacheValueKind::TimingMeasurement,
        ProbeType::HeaderMarker->value => SharedCacheValueKind::MarkerResult,
        ProbeType::AnonymityHeaders->value => SharedCacheValueKind::AnonymityHeaderFlags,
        ProbeType::UdpAssociate->value => SharedCacheValueKind::MarkerResult,
        ProbeType::DnsResolution->value => SharedCacheValueKind::DnsObservation,
        ProbeType::TelegramDcConnectivity->value => SharedCacheValueKind::TimingMeasurement,
        ProbeType::MtprotoHandshake->value => SharedCacheValueKind::MarkerResult,
        ProbeType::BandwidthTransfer->value => SharedCacheValueKind::TimingMeasurement,
    ];

    private readonly FailureTaxonomy $taxonomy;

    /**
     * @param  ProbeExecutor  $executor  Decorated orchestrator (miss path only).
     * @param  ProbeCache  $cache  Shared raw-probe cache.
     * @param  CachePolicy  $policy  Master switch + TTL table.
     * @param  ProbeCacheKeyFactory  $keyFactory  Builds ProbeCacheKeyV3 from task + node config.
     * @param  ProbeCacheMetrics  $metrics  Hit/miss counters.
     * @param  string  $semanticsBase  Probe-semantics version composed into
     *                                 probeSemanticsVersion (bump when observation
     *                                 semantics change without a tool change).
     */
    public function __construct(
        private readonly ProbeExecutor $executor,
        private readonly ProbeCache $cache,
        private readonly CachePolicy $policy,
        private readonly ProbeCacheKeyFactory $keyFactory,
        private readonly ProbeCacheMetrics $metrics,
        private readonly string $semanticsBase = 'v1',
    ) {
        $this->taxonomy = new FailureTaxonomy();
    }

    /**
     * Run one probe spec (judge fan-out included) through the shared cache.
     *
     * @return list<ProbeSingleResult>
     */
    public function run(AuditTaskV1 $task, ProbeExecutionSpecV1 $probe, ?JudgeSetSnapshot $judgeSet): array
    {
        if (! $this->policy->enabled) {
            // Zero cache I/O: straight delegation, cache state is irrelevant.
            return $this->delegate($task, $probe, $judgeSet);
        }

        $kind = self::KIND_BY_PROBE_TYPE[$probe->probeType->value]
            ?? SharedCacheValueKind::MarkerResult;
        $key = $this->keyFactory->build($task, $probe->probeType, $this->semanticsBase);

        $entry = $this->cache->get($key, $kind);

        if ($entry !== null) {
            $this->metrics->hit($kind->value);

            return $this->resultsFromEntry($probe->probeType, $entry);
        }

        $cachedCode = $this->cache->getNegative($key);

        if ($cachedCode !== null) {
            $this->metrics->negativeHit($kind->value);

            return [$this->negativeResult($probe->probeType, $cachedCode)];
        }

        $this->metrics->miss($kind->value);

        return $this->executeAndStore($task, $probe, $judgeSet, $key, $kind);
    }

    /**
     * Miss path: run through the decorated executor, store raw evidence.
     *
     * @return list<ProbeSingleResult>
     */
    private function executeAndStore(
        AuditTaskV1 $task,
        ProbeExecutionSpecV1 $probe,
        ?JudgeSetSnapshot $judgeSet,
        ProbeCacheKeyV3 $key,
        SharedCacheValueKind $kind,
    ): array {
        $results = $this->delegate($task, $probe, $judgeSet);

        $stored = false;

        foreach ($results as $result) {
            if ($result->toolResult->ok) {
                // One key per (task, probe type): with judge fan-out only the
                // first successful execution is stored — additional fan-out
                // targets would overwrite each other under the same key.
                if (! $stored) {
                    $stored = $this->storeSuccess($key, $kind, $result);
                }

                continue;
            }

            $failure = $result->toolResult->failure;

            if ($failure instanceof ExecutionFailure && self::isNegativeCacheable($failure)) {
                $this->cache->putNegative($key, $failure->descriptor()->code->value);
                $this->metrics->stored($kind->value);
            }
            // Non-classifiable execution failures (e.g. ToolUnavailable
            // provisioning faults) and proxy observations are never cached:
            // the next run dispatches again.
        }

        return $results;
    }

    /**
     * Delegate a single probe spec to the executor by handing it a one-probe
     * copy of the task (composition: ProbeExecutor plans per task).
     *
     * @return list<ProbeSingleResult>
     */
    private function delegate(AuditTaskV1 $task, ProbeExecutionSpecV1 $probe, ?JudgeSetSnapshot $judgeSet): array
    {
        $single = new AuditTaskV1(
            job: $task->job,
            tenantId: $task->tenantId,
            accessRef: $task->accessRef,
            sealedCredential: $task->sealedCredential,
            credentialReference: $task->credentialReference,
            probes: [$probe],
            policySnapshotVersion: $task->policySnapshotVersion,
            deadline: $task->deadline,
            maxAttempts: $task->maxAttempts,
            accessId: $task->accessId,
        );

        return $this->executor->execute($single, $judgeSet)->results;
    }

    /**
     * Flatten a successful result into a SharedCacheValue. Observations are
     * reduced to scalars (non-scalar values are dropped before put —
     * SharedCacheValue rejects them and dropping must never throw); timings
     * and the judge id travel under reserved payload keys so a hit can
     * rebuild the ProbeToolResult verbatim. Values rejected by the INV-005
     * key filter are skipped silently — the cache never breaks a probe run.
     */
    private function storeSuccess(
        ProbeCacheKeyV3 $key,
        SharedCacheValueKind $kind,
        ProbeSingleResult $result,
    ): bool {
        $payload = [];

        foreach ($result->toolResult->observations as $name => $value) {
            if (is_scalar($value) || $value === null) {
                $payload[(string) $name] = $value;
            }
        }

        foreach ($result->toolResult->timingsMs as $name => $ms) {
            $payload[self::TIMING_PREFIX.$name] = $ms;
        }

        if ($result->judgeId !== null) {
            $payload[self::JUDGE_KEY] = $result->judgeId;
        }

        try {
            $this->cache->put($key, new SharedCacheValue($kind, $payload));
        } catch (RuntimeException) {
            // INV-005 filter rejected an observation key — store nothing.
            return false;
        }

        $this->metrics->stored($kind->value);

        return true;
    }

    /**
     * Rebuild list<ProbeSingleResult> from a stored entry, verbatim:
     * observations and timings come back exactly as the tool returned them.
     *
     * @return list<ProbeSingleResult>
     */
    private function resultsFromEntry(ProbeType $probeType, ProbeCacheEntry $entry): array
    {
        $observations = [];
        $timingsMs = [];
        $judgeId = null;

        foreach ($entry->value->payload as $key => $value) {
            $name = (string) $key;

            if (str_starts_with($name, self::TIMING_PREFIX)) {
                $timingsMs[substr($name, strlen(self::TIMING_PREFIX))] = (float) $value;

                continue;
            }

            if ($name === self::JUDGE_KEY) {
                $judgeId = (string) $value;

                continue;
            }

            $observations[$name] = $value;
        }

        return [
            new ProbeSingleResult(
                probeType: $probeType,
                judgeId: $judgeId,
                toolResult: ProbeToolResult::ok($observations, $timingsMs),
            ),
        ];
    }

    /**
     * Short-circuit result for a cached negative marker: a fresh
     * ProbeToolResult::failed carrying the same FailureCode — no dispatch.
     */
    private function negativeResult(ProbeType $probeType, string $failureCode): ProbeSingleResult
    {
        $code = FailureCode::tryFrom($failureCode) ?? FailureCode::ToolTimeout;
        $failure = new ExecutionFailure($this->taxonomy->descriptor($code), [
            'source' => 'negative_cache',
        ]);

        return new ProbeSingleResult(
            probeType: $probeType,
            judgeId: null,
            toolResult: ProbeToolResult::failed($failure, []),
        );
    }

    /**
     * Negative caching applies to classifyable TOOL_* checker faults only
     * (plan §11.7): a transport timeout is a property of the endpoint and
     * may be remembered. ToolUnavailable is a checker-side provisioning
     * fault (governor at capacity, tool missing, no judge) that says
     * nothing about the endpoint — never cached.
     */
    private static function isNegativeCacheable(ExecutionFailure $failure): bool
    {
        return match ($failure->descriptor()->code) {
            FailureCode::ToolTimeout,
            FailureCode::ToolCrash,
            FailureCode::ToolProtocolError,
            FailureCode::ToolOom,
            FailureCode::ToolExitFailure,
            FailureCode::ToolOutputInvalid => true,
            default => false,
        };
    }
}
