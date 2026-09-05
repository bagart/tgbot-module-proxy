<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Models;

use BAGArt\ProxyOperations\Database\Factories\ProxyPoolFactory;
use BAGArt\ProxyOperations\Domain\Pool\PoolKind;
use BAGArt\ProxyOperations\Domain\Pool\PoolPredicate;
use BAGArt\ProxyOperations\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tenant-owned pool definition (plan §§11.21, 11.25). The definition (kind +
 * predicate) is the truth; proxy_pool_members rows are a projection.
 *
 * @property string $id
 * @property int $tenant_id
 * @property string $name
 * @property PoolKind $kind
 * @property array<string, mixed>|null $predicate
 * @property bool $enabled
 * @property string|null $description
 * @property int $policy_version
 * @property string|null $last_materialization_id
 * @property \Illuminate\Support\Carbon|null $last_materialized_at
 */
final class ProxyPool extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'name',
        'kind',
        'predicate',
        'enabled',
        'description',
        'policy_version',
        'last_materialization_id',
        'last_materialized_at',
    ];

    protected $attributes = [
        'enabled' => true,
        'policy_version' => 1,
    ];

    /**
     * Decoded predicate DTO; null for static pools.
     */
    public function predicateDto(): ?PoolPredicate
    {
        return $this->predicate === null ? null : PoolPredicate::fromJson($this->predicate);
    }

    public function members(): HasMany
    {
        return $this->hasMany(ProxyPoolMember::class, 'pool_id');
    }

    protected function casts(): array
    {
        return [
            'kind' => PoolKind::class,
            'predicate' => 'array',
            'enabled' => 'boolean',
            'policy_version' => 'integer',
            'last_materialized_at' => 'datetime',
        ];
    }

    protected static function newFactory(): Factory
    {
        return ProxyPoolFactory::new();
    }
}
