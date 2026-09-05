<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Models;

use BAGArt\ProxyOperations\Database\Factories\ProxySourceFactory;
use BAGArt\ProxyOperations\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Where inventory proxies came from (plan §11.21): paste, file upload,
 * recurring feed or manual entry. Feed rows carry the R6.8 import key
 * ingredients (`feed_id` + `import_policy_version`) used by the future
 * idempotent tenant import.
 *
 * @property string $id
 * @property int $tenant_id
 * @property SourceKind $kind
 * @property string|null $label
 * @property string|null $feed_id
 * @property string|null $import_policy_version
 * @property bool $enabled
 * @property Carbon|null $last_synced_at
 */
final class ProxySource extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'kind',
        'label',
        'feed_id',
        'import_policy_version',
        'enabled',
        'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'kind' => SourceKind::class,
            'enabled' => 'boolean',
            'last_synced_at' => 'datetime',
        ];
    }

    protected static function newFactory(): Factory
    {
        return ProxySourceFactory::new();
    }
}
