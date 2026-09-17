<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Gateway API token model (plan §§10.12 п.14, 11.10).
 * API keys for external consumers with rate limiting and scope.
 */
class GatewayToken extends Model
{
    protected $fillable = [
        'tenant_id',
        'name',
        'token_hash',
        'scopes',
        'rate_limit',
        'expires_at',
        'last_used_at',
        'is_active',
    ];

    protected $casts = [
        'scopes' => 'array',
        'expires_at' => 'datetime',
        'last_used_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(\BAGArt\ProxyOperations\Models\WorkspaceSettings::class, 'tenant_id', 'workspace_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function isValid(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return false;
        }

        return true;
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes ?? [], true);
    }
}
