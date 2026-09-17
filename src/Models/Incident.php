<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Incident tracking model (plan §§10.12 п.12, 11.10).
 * Records operational incidents with severity, status, and action history.
 */
class Incident extends Model
{
    protected $fillable = [
        'tenant_id',
        'title',
        'description',
        'severity',
        'status',
        'source',
        'affected_endpoints',
        'resolved_at',
    ];

    protected $casts = [
        'severity' => IncidentSeverity::class,
        'status' => IncidentStatus::class,
        'affected_endpoints' => 'array',
        'resolved_at' => 'datetime',
    ];

    public function actions(): HasMany
    {
        return $this->hasMany(IncidentAction::class);
    }

    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeOpen($query)
    {
        return $query->whereNot('status', IncidentStatus::Resolved->value);
    }
}
