<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Models;

use BAGArt\ProxyOperations\Database\Factories\ProxyObservationFactory;
use BAGArt\ProxyOperations\Domain\Failure\FailureClass;
use BAGArt\ProxyOperations\Domain\Failure\FailureCode;
use BAGArt\ProxyOperations\Domain\Probe\ProbeProfile;
use BAGArt\ProxyOperations\Domain\Probe\ProbeType;
use BAGArt\ProxyOperations\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Append-only, immutable raw probe observation (plan §11.7, §11.16, §11.21).
 * Rows are written once from Wire\AuditResultV1 and never updated or deleted;
 * any mutation attempt raises ImmutableRecordException. INV-014/015:
 * execution failures (Checker/Platform classes) never persist here. Evidence
 * carries tenant-neutral raw measurements only (R6.4 allowlist).
 *
 * @property string $id
 * @property int $tenant_id
 * @property string $access_id
 * @property Carbon $checked_at
 * @property ProbeType $probe_type
 * @property ProbeProfile $probe_profile
 * @property string $probe_profile_version
 * @property string|null $judge_set_version
 * @property string|null $checker_node_id
 * @property string|null $checker_region
 * @property string|null $policy_snapshot_id
 * @property string $outcome
 * @property FailureCode|null $failure_code
 * @property FailureClass|null $failure_class
 * @property array<string, mixed> $evidence
 * @property int $schema_version
 */
final class ProxyObservation extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuids;

    public const int SCHEMA_VERSION = 1;

    /**
     * Substring patterns (case-insensitive) marking an evidence key as tenant
     * interpretation — same key policy as Domain\Cache\SharedCacheValue.
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
     * Substring patterns (case-insensitive) marking an evidence key as
     * secret-bearing (R6.4: no credentials/auth headers/cookies/tokens).
     * Response bodies are not keyed here: `body_hash` and `content_length`
     * are allowlisted raw measurements.
     */
    private const array FORBIDDEN_SECRET_PATTERNS = [
        'password',
        'secret',
        'authorization',
        'cookie',
        'token',
    ];

    protected const string OUTCOME_SUCCESS = 'success';

    protected const string OUTCOME_FAILURE = 'failure';

    protected $fillable = [
        'access_id',
        'checked_at',
        'probe_type',
        'probe_profile',
        'probe_profile_version',
        'policy_snapshot_id',
        'judge_set_version',
        'checker_node_id',
        'checker_region',
        'outcome',
        'failure_code',
        'failure_class',
        'evidence',
        'schema_version',
    ];

    // Append-only: created_at is stamped on insert; there is no updated_at.
    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        self::creating(function (self $observation): void {
            $observation->assertPersistable();
        });

        self::updating(function (): void {
            throw new ImmutableRecordException('Proxy observations are append-only and must not be updated.');
        });

        self::deleting(function (): void {
            throw new ImmutableRecordException('Proxy observations are append-only and must not be deleted.');
        });
    }

    public function access(): BelongsTo
    {
        return $this->belongsTo(ProxyAccess::class);
    }

    protected function assertPersistable(): void
    {
        if (! in_array($this->outcome, [self::OUTCOME_SUCCESS, self::OUTCOME_FAILURE], true)) {
            throw new InvalidArgumentException('Outcome must be either "success" or "failure".');
        }

        if ($this->outcome === self::OUTCOME_SUCCESS) {
            $successCode = $this->attributes['failure_code'] ?? null;
            $successClass = $this->attributes['failure_class'] ?? null;

            if ($successCode !== null || $successClass !== null) {
                throw new InvalidArgumentException('A successful observation must not carry a failure code or class.');
            }
        } else {
            $rawCode = $this->attributes['failure_code'] ?? null;

            if ($rawCode === null || $rawCode === '') {
                throw new InvalidArgumentException('A failed observation requires a failure code.');
            }

            $codeValue = $rawCode instanceof FailureCode ? $rawCode->value : (string) $rawCode;

            $code = FailureCode::tryFrom($codeValue)
                ?? throw new InvalidArgumentException('Unknown failure code: '.var_export($codeValue, true));

            // INV-014/015: TOOL_* / Checker|Platform-class codes are execution
            // failures of the checker infrastructure — they never become proxy
            // observations.
            if ($code->class()->isExecutionFailure()) {
                throw new InvalidArgumentException(sprintf(
                    'Execution failure %s (%s) must not be recorded as a proxy observation.',
                    $code->value,
                    $code->class()->value,
                ));
            }

            $rawClass = $this->attributes['failure_class'] ?? null;
            $classValue = $rawClass instanceof FailureClass ? $rawClass->value : (string) $rawClass;

            if ($rawClass !== null && $classValue !== $code->class()->value) {
                throw new InvalidArgumentException('Failure class does not match the failure code.');
            }
        }

        self::assertSafeEvidence(is_array($this->evidence) ? $this->evidence : []);
    }

    /**
     * R6.4 evidence allowlist guard: rejects any key that carries tenant
     * interpretation or secrets (credentials, auth headers, cookies, tokens,
     * response bodies). Applied recursively over nested payloads.
     *
     * @param  array<string, mixed>  $evidence
     */
    private static function assertSafeEvidence(array $evidence): void
    {
        foreach ($evidence as $key => $value) {
            if (! is_string($key)) {
                throw new InvalidArgumentException('Evidence keys must be strings.');
            }

            $normalized = strtolower(preg_replace('/[^a-z0-9]+/i', '', $key));

            foreach (self::FORBIDDEN_INTERPRETATION_PATTERNS as $pattern) {
                if (str_contains($normalized, $pattern)) {
                    throw new InvalidArgumentException('Evidence must not carry tenant interpretation keys.');
                }
            }

            foreach (self::FORBIDDEN_SECRET_PATTERNS as $pattern) {
                if (str_contains($normalized, $pattern)) {
                    throw new InvalidArgumentException('Evidence must not carry secret-bearing keys.');
                }
            }

            if (is_array($value)) {
                self::assertSafeEvidence($value);
            }
        }
    }

    protected function casts(): array
    {
        return [
            'checked_at' => 'datetime',
            'probe_type' => ProbeType::class,
            'probe_profile' => ProbeProfile::class,
            'outcome' => 'string',
            'failure_code' => FailureCode::class,
            'failure_class' => FailureClass::class,
            'evidence' => 'array',
            'schema_version' => 'integer',
        ];
    }

    protected static function newFactory(): Factory
    {
        return ProxyObservationFactory::new();
    }
}
