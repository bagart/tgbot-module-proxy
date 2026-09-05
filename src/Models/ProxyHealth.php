<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Models;

use BAGArt\ProxyOperations\Database\Factories\ProxyHealthFactory;
use BAGArt\ProxyOperations\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Derived health projection (plan §§11.7, 11.26), INV-001: strictly
 * ACCESS-scoped, one row per (tenant, access). Written only by the future
 * health engine — persistence-only model. R6.6: every derived value stores its
 * formula/classifier version; IMPROVE#1 keeps capability_score separate from
 * health_score.
 *
 * @property string $id
 * @property int $tenant_id
 * @property string $access_id
 * @property int|null $health_score
 * @property int|null $capability_score
 * @property array<string, mixed>|null $target_health
 * @property array<string, mixed>|null $latency_percentiles
 * @property array<string, string>|null $dimension_signals
 * @property string|null $anonymity_tier
 * @property string|null $anonymity_classifier_version
 * @property string $health_formula_version
 * @property Carbon|null $fresh_until
 * @property Carbon|null $computed_at
 */
final class ProxyHealth extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuids;

    protected $table = 'proxy_health';

    protected $fillable = [
        'access_id',
        'health_score',
        'capability_score',
        'target_health',
        'latency_percentiles',
        'dimension_signals',
        'anonymity_tier',
        'anonymity_classifier_version',
        'health_formula_version',
        'fresh_until',
        'computed_at',
    ];

    public function access(): BelongsTo
    {
        return $this->belongsTo(ProxyAccess::class);
    }

    protected function casts(): array
    {
        return [
            'health_score' => 'integer',
            'capability_score' => 'integer',
            'target_health' => 'array',
            'latency_percentiles' => 'array',
            'dimension_signals' => 'array',
            'fresh_until' => 'datetime',
            'computed_at' => 'datetime',
        ];
    }

    protected static function newFactory(): Factory
    {
        return ProxyHealthFactory::new();
    }
}
