<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Models;

use BAGArt\ProxyOperations\Database\Factories\ProxyAuditAttemptFactory;
use BAGArt\ProxyOperations\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One execution try of an audit job (plan §§11.18, 11.19, 11.21). Worker
 * results report `task_id + attempt_id` with an eternal TTL (§11.19); the
 * (job_id, attempt_no) unique constraint is the DB-side idempotency gate.
 *
 * @property string $id
 * @property int $tenant_id
 * @property string $job_id
 * @property int $attempt_no
 * @property string|null $worker_node
 * @property AuditAttemptStatus $status
 * @property string|null $result_code
 * @property \Illuminate\Support\Carbon|null $started_at
 * @property \Illuminate\Support\Carbon|null $finished_at
 */
final class ProxyAuditAttempt extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'job_id',
        'attempt_no',
        'worker_node',
        'status',
        'result_code',
        'started_at',
        'finished_at',
    ];

    public const UPDATED_AT = null;

    public function job(): BelongsTo
    {
        return $this->belongsTo(ProxyAuditJob::class, 'job_id');
    }

    protected function casts(): array
    {
        return [
            'attempt_no' => 'integer',
            'status' => AuditAttemptStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    protected static function newFactory(): Factory
    {
        return ProxyAuditAttemptFactory::new();
    }
}
