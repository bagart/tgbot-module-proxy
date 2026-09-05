<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Models;

use BAGArt\ProxyOperations\Database\Factories\ProxyPoolMemberFactory;
use BAGArt\ProxyOperations\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Materialized pool membership (plan §11.25) — a projection row, not a
 * second source of truth. materialization_version NULL = hand-picked static
 * member (survives dynamic rebuilds); non-null = produced by that
 * materialization run.
 *
 * @property string $id
 * @property int $tenant_id
 * @property string $pool_id
 * @property string $access_id
 * @property Carbon $added_at
 * @property int|null $materialization_version
 */
final class ProxyPoolMember extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuids;

    // The table stamps added_at explicitly; no created_at/updated_at columns.
    public $timestamps = false;

    protected $fillable = [
        'pool_id',
        'access_id',
        'added_at',
        'materialization_version',
    ];

    public function pool(): BelongsTo
    {
        return $this->belongsTo(ProxyPool::class, 'pool_id');
    }

    public function access(): BelongsTo
    {
        return $this->belongsTo(ProxyAccess::class, 'access_id');
    }

    protected function casts(): array
    {
        return [
            'added_at' => 'datetime',
            'materialization_version' => 'integer',
        ];
    }

    protected static function newFactory(): Factory
    {
        return ProxyPoolMemberFactory::new();
    }
}
