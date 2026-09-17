<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Incident action log (plan §§10.12 п.12).
 * Records actions taken during incident resolution.
 */
class IncidentAction extends Model
{
    protected $fillable = [
        'incident_id',
        'action_type',
        'description',
        'performed_by',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }
}
