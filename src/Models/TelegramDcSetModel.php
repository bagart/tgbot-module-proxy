<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Models;

use BAGArt\ProxyOperations\Domain\Snapshot\TelegramDc;
use BAGArt\ProxyOperations\Domain\Snapshot\TelegramDcSet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Eloquent read-only model for the telegram_dc_sets table.
 * NOT tenant-scoped — this is a global system table (plan §11.35 п.13).
 *
 * @property string $id
 * @property int $version
 * @property int $dc_id
 * @property array<string> $addresses
 * @property array<int> $ports
 * @property bool $enabled
 * @property string|null $description
 * @property Carbon $created_at
 */
class TelegramDcSetModel extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'telegram_dc_sets';

    protected $guarded = [];

    public $timestamps = false;

    protected $casts = [
        'addresses' => 'array',
        'ports' => 'array',
        'enabled' => 'boolean',
        'created_at' => 'datetime',
    ];

    /**
     * Hydrate the immutable TelegramDcSet DTO from the latest version in DB.
     */
    public function toDto(): TelegramDcSet
    {
        return new TelegramDcSet(
            version: $this->version,
            dcs: array_map(
                static fn (self $row): TelegramDc => new TelegramDc(
                    dcId: $row->dc_id,
                    addresses: $row->addresses,
                    ports: $row->ports,
                    enabled: $row->enabled,
                ),
                $this->where('version', $this->version)->orderBy('dc_id')->get()->all(),
            ),
            frozenAt: $this->created_at->toIso8601String(),
        );
    }

    /**
     * Load the latest DC set version as a DTO.
     */
    public static function latestSet(): ?TelegramDcSet
    {
        $latest = self::query()->orderByDesc('version')->first();

        if ($latest === null) {
            return null;
        }

        return $latest->toDto();
    }
}
