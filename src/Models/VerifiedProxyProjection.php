<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Models;

use BAGArt\ProxyOperations\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Verified proxy projection — read-only model (plan §11.35 items 4,11).
 * Updated by single projector on AuditCompleted; never mutated directly.
 *
 * @property string $id
 * @property int $tenant_id
 * @property string $access_id
 * @property string $endpoint_id
 * @property string $protocol
 * @property array $credential_projection
 * @property array $capabilities_projection
 * @property array $health_projection
 * @property bool|null $telegram_usable
 * @property string $verification_policy_version
 * @property Carbon $verified_at
 * @property Carbon|null $last_checked_at
 * @property int $schema_version
 */
class VerifiedProxyProjection extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'verified_proxies';

    protected $guarded = [];

    protected $casts = [
        'credential_projection' => 'array',
        'capabilities_projection' => 'array',
        'health_projection' => 'array',
        'telegram_usable' => 'boolean',
        'verified_at' => 'datetime',
        'last_checked_at' => 'datetime',
        'schema_version' => 'integer',
    ];
}
