<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Models;

use BAGArt\ProxyOperations\Database\Factories\RawFeedEntryFactory;
use BAGArt\ProxyOperations\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only staging row for raw import candidates (plan §§11.21, 11.28,
 * 11.35 п.16–17). Every import source (bot paste, file upload, feed sync,
 * API, CLI) writes raw lines here before the parser processes them.
 * Rows are written once and never updated or deleted.
 *
 * @property string $id
 * @property int $tenant_id
 * @property int|null $source_id
 * @property string $import_batch_id
 * @property string $batch_hash
 * @property string $raw_line
 * @property int $line_number
 * @property RawFeedEntryStatus $status
 * @property array<string, mixed>|null $parsed_entry_json
 * @property array<string, mixed>|null $parse_error_json
 * @property int|null $endpoint_id
 */
final class RawFeedEntry extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'source_id',
        'import_batch_id',
        'batch_hash',
        'batch_level_hash',
        'raw_line',
        'line_number',
        'status',
        'parsed_entry_json',
        'parse_error_json',
        'endpoint_id',
    ];

    protected static function booted(): void
    {
        self::updating(function (): void {
            throw new ImmutableRecordException('Raw feed entries are append-only and must not be updated.');
        });

        self::deleting(function (): void {
            throw new ImmutableRecordException('Raw feed entries are append-only and must not be deleted.');
        });
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(ProxySource::class);
    }

    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(ProxyEndpoint::class);
    }

    protected function casts(): array
    {
        return [
            'line_number' => 'integer',
            'status' => RawFeedEntryStatus::class,
            'parsed_entry_json' => 'array',
            'parse_error_json' => 'array',
        ];
    }

    protected static function newFactory(): Factory
    {
        return RawFeedEntryFactory::new();
    }
}
