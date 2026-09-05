<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Wire;

use BAGArt\ProxyOperations\Domain\Failure\ExecutionFailure;
use BAGArt\ProxyOperations\Domain\Failure\Failure;
use BAGArt\ProxyOperations\Domain\Failure\FailureCode;
use BAGArt\ProxyOperations\Domain\Failure\FailureDescriptor;
use BAGArt\ProxyOperations\Domain\Failure\FailureTaxonomy;
use BAGArt\ProxyOperations\Domain\Failure\ProxyFailure;
use InvalidArgumentException;
use JsonSerializable;
use RuntimeException;

/**
 * Result of one audit attempt sent Worker → application layer (plan §11.9,
 * §11.39 пп.13–14). Raw and tenant-neutral: interpretation (health, lifecycle,
 * capability) happens exclusively in the application/domain layer.
 *
 * Structural invariant (INV-014/015): `observations` carry only proxy-side
 * failures (classes Proxy/Target/Judge/Policy); checker-infrastructure faults
 * (Checker/Platform classes — TOOL_*, REDIS_*, STORAGE_*) go to
 * `executionFailures` only and never become proxy observations.
 *
 * OD-5 resolution: successful probe data travels in the additive optional
 * `probeData` field — `array<string, list<array<string,mixed>>>` keyed by
 * probe type, each entry a raw-observation record restricted to the
 * shared-cache value allowlist (§11.35 п.2). Tenant interpretation (health,
 * scores, lifecycle, quarantine, policy-derived tiers) and secrets are
 * rejected at construction — fail closed, like SharedCacheValue (INV-005).
 * The field is optional (default `[]`), so V1 payloads without it still
 * parse; `SCHEMA_VERSION` stays 1 (backward-compatible addition).
 */
final readonly class AuditResultV1 implements JsonSerializable
{
    public const int SCHEMA_VERSION = 1;

    /**
     * Substring patterns (case-insensitive) marking a probeData record key as
     * tenant interpretation — same key policy as Domain\Cache\SharedCacheValue.
     */
    private const array FORBIDDEN_INTERPRETATION_PATTERNS = [
        'health',
        'score',
        'lifecycle',
        'verified',
        'eligibility',
        'quarantine',
        'verdict',
        'classification',
        'tier',
        'usable',
        'state',
    ];

    /**
     * Substring patterns (case-insensitive) marking a probeData record key as
     * secret-bearing (R6.4: no credentials/auth headers/cookies/tokens).
     */
    private const array FORBIDDEN_SECRET_PATTERNS = [
        'password',
        'secret',
        'authorization',
        'cookie',
        'token',
    ];

    /**
     * @param  list<ProxyFailure>  $observations  Proxy-side failures plus raw probe payloads in `$context`.
     * @param  list<ExecutionFailure>  $executionFailures  Checker-infrastructure faults (TOOL_* / REDIS_* / STORAGE_*).
     * @param  array<string,int|float>  $timings  Named durations, e.g. totalMs.
     * @param  string  $accessId  ProxyAccess UUID the worker echoed from the task; '' for legacy senders.
     * @param  array<string, list<array<string,mixed>>>  $probeData  Successful raw probe records keyed by probe type (OD-5, optional).
     */
    public function __construct(
        public readonly string $taskId,
        public readonly string $attemptId,
        public readonly AuditResultStatus $status,
        public readonly array $observations,
        public readonly array $executionFailures,
        public readonly array $timings,
        public readonly string $checkerNodeId,
        public readonly string $accessId = '',
        public readonly array $probeData = [],
    ) {
        foreach ($this->observations as $observation) {
            if (! $observation instanceof ProxyFailure) {
                throw new InvalidArgumentException('AuditResult observations must be ProxyFailure instances.');
            }

            if ($observation->descriptor->class->isExecutionFailure()) {
                throw new InvalidArgumentException('Execution-class failures must go to executionFailures, never observations (INV-014/015).');
            }
        }

        foreach ($this->executionFailures as $failure) {
            if (! $failure instanceof ExecutionFailure) {
                throw new InvalidArgumentException('AuditResult executionFailures must be ExecutionFailure instances.');
            }

            if (! $failure->descriptor->class->isExecutionFailure()) {
                throw new InvalidArgumentException('Only Checker/Platform class failures belong to executionFailures.');
            }
        }

        self::assertProbeDataSharable($this->probeData);
    }

    /**
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'taskId' => $this->taskId,
            'attemptId' => $this->attemptId,
            'status' => $this->status->value,
            'observations' => array_map(self::failureToJson(...), $this->observations),
            'executionFailures' => array_map(self::failureToJson(...), $this->executionFailures),
            'timings' => $this->timings,
            'checkerNodeId' => $this->checkerNodeId,
            'accessId' => $this->accessId,
            'probeData' => $this->probeData,
            'schemaVersion' => self::SCHEMA_VERSION,
        ];
    }

    /**
     * OD-5 allowlist guard: probeData records must be flat scalar payloads
     * keyed by strings free of tenant-interpretation and secret-bearing
     * patterns (same policy as Domain\Cache\SharedCacheValue, INV-005).
     *
     * @param  array<string, list<array<string,mixed>>>  $probeData
     */
    private static function assertProbeDataSharable(array $probeData): void
    {
        foreach ($probeData as $probeType => $records) {
            if (! is_string($probeType) || $probeType === '') {
                throw new InvalidArgumentException('ProbeData keys must be non-empty probe type strings.');
            }

            if (! is_array($records) || ! array_is_list($records)) {
                throw new InvalidArgumentException('ProbeData values must be lists of raw-observation records.');
            }

            foreach ($records as $record) {
                if (! is_array($record)) {
                    throw new InvalidArgumentException('ProbeData records must be arrays.');
                }

                foreach ($record as $key => $value) {
                    if (! is_string($key)) {
                        throw new InvalidArgumentException('ProbeData record keys must be strings.');
                    }

                    if (! is_scalar($value) && $value !== null) {
                        throw new InvalidArgumentException('ProbeData record values must be scalar.');
                    }

                    $normalized = strtolower(preg_replace('/[^a-z0-9]+/i', '', $key));

                    foreach (self::FORBIDDEN_INTERPRETATION_PATTERNS as $pattern) {
                        if (str_contains($normalized, $pattern)) {
                            throw new InvalidArgumentException('ProbeData must not carry tenant interpretation keys.');
                        }
                    }

                    foreach (self::FORBIDDEN_SECRET_PATTERNS as $pattern) {
                        if (str_contains($normalized, $pattern)) {
                            throw new InvalidArgumentException('ProbeData must not carry secret-bearing keys.');
                        }
                    }
                }
            }
        }
    }

    /**
     * @return array<string,mixed>
     */
    private static function failureToJson(Failure $failure): array
    {
        return [
            'code' => $failure->descriptor()->code->value,
            'context' => $failure->context(),
        ];
    }

    /**
     * @param  array<string,mixed>  $data
     *
     * @throws RuntimeException If the format is not recognized or an execution-class code appears among observations.
     */
    public static function fromJson(array $data): self
    {
        return match ($data['schemaVersion'] ?? self::SCHEMA_VERSION) {
            self::SCHEMA_VERSION => self::fromJsonV1($data),
            default => throw new RuntimeException('Unsupported AuditResult schemaVersion: '.var_export($data['schemaVersion'], true)),
        };
    }

    /**
     * @param  array<string,mixed>  $data
     */
    private static function fromJsonV1(array $data): self
    {
        $observations = [];

        foreach ((array) ($data['observations'] ?? []) as $entry) {
            $observations[] = self::proxyFailureFromJson((array) $entry);
        }

        $executionFailures = [];

        foreach ((array) ($data['executionFailures'] ?? []) as $entry) {
            $executionFailures[] = self::executionFailureFromJson((array) $entry);
        }

        return new self(
            taskId: (string) ($data['taskId'] ?? ''),
            attemptId: (string) ($data['attemptId'] ?? ''),
            status: AuditResultStatus::from((string) ($data['status'] ?? '')),
            observations: $observations,
            executionFailures: $executionFailures,
            timings: (array) ($data['timings'] ?? []),
            checkerNodeId: (string) ($data['checkerNodeId'] ?? ''),
            accessId: (string) ($data['accessId'] ?? ''),
            probeData: (array) ($data['probeData'] ?? []),
        );
    }

    /**
     * @param  array<string,mixed>  $data
     */
    private static function proxyFailureFromJson(array $data): ProxyFailure
    {
        $code = FailureCode::from((string) $data['code']);

        if ($code->class()->isExecutionFailure()) {
            throw new RuntimeException("Observation payload carries an execution-class code {$code->value} (INV-014/015).");
        }

        return new ProxyFailure(self::descriptor($code), self::contextFromJson($data));
    }

    /**
     * @param  array<string,mixed>  $data
     */
    private static function executionFailureFromJson(array $data): ExecutionFailure
    {
        $code = FailureCode::from((string) $data['code']);

        if (! $code->class()->isExecutionFailure()) {
            throw new RuntimeException("Execution-failure payload carries a non-execution code {$code->value}.");
        }

        return new ExecutionFailure(self::descriptor($code), self::contextFromJson($data));
    }

    private static function descriptor(FailureCode $code): FailureDescriptor
    {
        return (new FailureTaxonomy)->descriptor($code);
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    private static function contextFromJson(array $data): array
    {
        return (array) ($data['context'] ?? []);
    }
}
