<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Models;

use BAGArt\ProxyOperations\Database\Factories\ProxyLeaseFactory;
use BAGArt\ProxyOperations\Domain\Lease\LeaseState;
use BAGArt\ProxyOperations\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Lease state row (plan §11.24) — Postgres truth for the recovery protocol.
 * Mutations go through LeaseService; the (access_id, active_marker) unique
 * index enforces one ACTIVE lease per AccessIdentity.
 *
 * @property string $id
 * @property int $tenant_id
 * @property string $access_id
 * @property string $holder
 * @property string $purpose
 * @property LeaseState $state
 * @property int|null $active_marker
 * @property Carbon $acquired_at
 * @property Carbon $expires_at
 * @property Carbon|null $released_at
 * @property int $renewals
 * @property array<string, mixed>|null $last_lease_event
 */
final class ProxyLease extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'access_id',
        'holder',
        'purpose',
        'state',
        'active_marker',
        'acquired_at',
        'expires_at',
        'released_at',
        'renewals',
        'last_lease_event',
    ];

    protected $attributes = [
        'purpose' => 'session',
        'renewals' => 0,
    ];

    public function access(): BelongsTo
    {
        return $this->belongsTo(ProxyAccess::class, 'access_id');
    }

    protected function casts(): array
    {
        return [
            'state' => LeaseState::class,
            'active_marker' => 'integer',
            'acquired_at' => 'datetime',
            'expires_at' => 'datetime',
            'released_at' => 'datetime',
            'renewals' => 'integer',
            'last_lease_event' => 'array',
        ];
    }

    protected static function newFactory(): Factory
    {
        return ProxyLeaseFactory::new();
    }
}
