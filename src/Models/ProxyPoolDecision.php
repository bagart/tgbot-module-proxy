<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Models;

use BAGArt\ProxyOperations\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Append-only decision-log row (plan §11.25): one candidate decision of a
 * materialization (T33) or selection (T35) run. Rows are never updated —
 * the model has no update paths and the table has insert time only.
 *
 * @property string $id
 * @property int $tenant_id
 * @property string $pool_id
 * @property string $materialization_id
 * @property string|null $access_id
 * @property string $decision
 * @property string $reason_code
 * @property float|null $score
 * @property int $policy_version
 * @property Carbon $created_at
 */
final class ProxyPoolDecision extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'pool_id',
        'materialization_id',
        'access_id',
        'decision',
        'reason_code',
        'score',
        'policy_version',
        'created_at',
    ];

    public function pool(): BelongsTo
    {
        return $this->belongsTo(ProxyPool::class, 'pool_id');
    }

    protected function casts(): array
    {
        return [
            'score' => 'float',
            'policy_version' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
