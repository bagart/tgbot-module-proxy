<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Models;

use BAGArt\ProxyOperations\Database\Factories\ProxyAuditJobFactory;
use BAGArt\ProxyOperations\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Logical audit job (plan §§11.18, 11.21): the user intent for one audit run.
 * Execution tries live in ProxyAuditAttempt rows; TaskDelivery identity stays
 * in Redis/T92 keys (§11.37 R6.7). Placement idempotency is a T24 concern via
 * the Redis dedup key — no DB unique constraint (TTL window semantics).
 *
 * @property string $id
 * @property int $tenant_id
 * @property AuditTrigger $trigger
 * @property string $policy_snapshot_id
 * @property int|null $requested_by
 * @property string $target_set_hash
 * @property AuditJobStatus $status
 * @property string|null $result_code
 * @property \Illuminate\Support\Carbon|null $started_at
 * @property \Illuminate\Support\Carbon|null $completed_at
 * @property \Illuminate\Support\Carbon $created_at
 */
final class ProxyAuditJob extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'trigger',
        'policy_snapshot_id',
        'requested_by',
        'target_set_hash',
        'status',
        'result_code',
        'started_at',
        'completed_at',
    ];

    // Placement time only; lifecycle transitions are explicit timestamps.
    public const UPDATED_AT = null;

    public function policySnapshot(): BelongsTo
    {
        return $this->belongsTo(PolicySnapshot::class);
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(ProxyAuditAttempt::class, 'job_id');
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeOfStatus(Builder $query, AuditJobStatus $status): Builder
    {
        return $query->where('status', $status->value);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeOfTrigger(Builder $query, AuditTrigger $trigger): Builder
    {
        return $query->where('trigger', $trigger->value);
    }

    protected function casts(): array
    {
        return [
            'trigger' => AuditTrigger::class,
            'status' => AuditJobStatus::class,
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    protected static function newFactory(): Factory
    {
        return ProxyAuditJobFactory::new();
    }
}
