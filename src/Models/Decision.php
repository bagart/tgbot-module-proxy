<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Decision log model (plan §§10.12 п.13, 11.10).
 * Records all significant decisions made by the system for audit and replay.
 */
class Decision extends Model
{
    protected $fillable = [
        'tenant_id',
        'decision_type',
        'entity_type',
        'entity_id',
        'action',
        'reason',
        'metadata',
        'user_id',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeOfType($query, DecisionType $type)
    {
        return $query->where('decision_type', $type->value);
    }

    public function scopeForEntity($query, string $entityType, ?int $entityId = null)
    {
        $query->where('entity_type', $entityType);

        if ($entityId !== null) {
            $query->where('entity_id', $entityId);
        }

        return $query;
    }
}
