<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Models;

use BAGArt\ProxyOperations\Database\Factories\ProxyCapabilityFactory;
use BAGArt\ProxyOperations\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Derived capability projection (plan §§11.4–11.5, 11.21): endpoint-level part
 * (protocol matrix slice) on rows with access_id = NULL, access-level part
 * (auth/udp/dns/tg) on access-bound rows. Written only by the future
 * capability evaluator — persistence-only model, no evaluators in Stage 1.
 * R6.6: capability_formula_version is stored next to every derived value.
 *
 * @property string $id
 * @property int $tenant_id
 * @property string $endpoint_id
 * @property string|null $access_id
 * @property bool|null $udp_associate_supported
 * @property string|null $dns_resolution_mode
 * @property array<string, mixed> $matrix
 * @property string $capability_formula_version
 * @property Carbon|null $evaluated_at
 */
final class ProxyCapability extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'endpoint_id',
        'access_id',
        'udp_associate_supported',
        'dns_resolution_mode',
        'matrix',
        'capability_formula_version',
        'evaluated_at',
    ];

    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(ProxyEndpoint::class);
    }

    public function access(): BelongsTo
    {
        return $this->belongsTo(ProxyAccess::class);
    }

    protected function casts(): array
    {
        return [
            'udp_associate_supported' => 'boolean',
            'matrix' => 'array',
            'evaluated_at' => 'datetime',
        ];
    }

    protected static function newFactory(): Factory
    {
        return ProxyCapabilityFactory::new();
    }
}
