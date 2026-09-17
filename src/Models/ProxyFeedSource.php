<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * External proxy feed source (plan §11.28). Tenant-scoped, cron-synced.
 */
final class ProxyFeedSource extends Model
{
    protected $fillable = [
        'tenant_id',
        'url',
        'format',
        'status',
        'schedule',
        'last_synced_at',
        'sync_interval_minutes',
        'filter_config',
    ];

    protected $casts = [
        'last_synced_at' => 'datetime',
        'sync_interval_minutes' => 'integer',
        'filter_config' => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(\BAGArt\TelegramBotManagement\Models\TgBot::class, 'tenant_id');
    }

    public function isActive(): bool
    {
        return $this->status === FeedSourceStatus::Active;
    }

    public function isDueForSync(): bool
    {
        if (! $this->isActive()) {
            return false;
        }

        if ($this->last_synced_at === null) {
            return true;
        }

        return $this->last_synced_at->addMinutes($this->sync_interval_minutes)->isPast();
    }
}
