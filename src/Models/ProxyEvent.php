<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Models;

use BAGArt\ProxyOperations\Domain\Cache\EventEnvelope;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One proxy event: the append-only event log row and the outbox row in one
 * (plan §§11.20, 11.35 пп.18–19, §11.37 R6.7). The envelope part — id
 * (event_id), event_type, schema_version, occurred_at, aggregate, payload,
 * sequence, tenant — is immutable after insert; only the dispatcher-owned
 * dispatch metadata may change. Rows are never deleted (archival via
 * partitions); any mutation or delete attempt of the immutable part raises
 * ImmutableRecordException.
 *
 * Deliberately NOT BelongsToTenant (INV-006 deviation, documented): tenant_id
 * comes from the envelope, never from the ambient TenantContext, and the
 * outbox dispatcher must run without a tenant scope over all tenants' rows.
 *
 * @property string $id
 * @property int $tenant_id
 * @property string $event_type
 * @property int $schema_version
 * @property Carbon $occurred_at
 * @property string $aggregate_type
 * @property string $aggregate_id
 * @property array<string, mixed> $payload
 * @property int $sequence
 * @property string $dispatch_status
 * @property int $attempt_count
 * @property Carbon|null $last_attempt_at
 * @property Carbon|null $consumed_at
 * @property Carbon $created_at
 */
final class ProxyEvent extends Model
{
    use HasUuids;

    public const string STATUS_PENDING = 'pending';

    public const string STATUS_DISPATCHED = 'dispatched';

    public const string STATUS_FAILED = 'failed';

    /**
     * Columns guarded by R6.7: once inserted, they never change.
     */
    private const array IMMUTABLE_COLUMNS = [
        'id',
        'tenant_id',
        'event_type',
        'schema_version',
        'occurred_at',
        'aggregate_type',
        'aggregate_id',
        'payload',
        'sequence',
    ];

    protected $fillable = [
        'id',
        'tenant_id',
        'event_type',
        'schema_version',
        'occurred_at',
        'aggregate_type',
        'aggregate_id',
        'payload',
        'sequence',
    ];

    // created_at is stamped on insert; dispatch metadata is updated in place
    // without an Eloquent updated_at stamp.
    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        self::updating(function (self $event): void {
            foreach (self::IMMUTABLE_COLUMNS as $column) {
                if ($event->isDirty($column)) {
                    throw ImmutableRecordException::forColumn(self::class, $column);
                }
            }
        });

        self::deleting(function (): void {
            // §11.35 п.19: dispatched events are never deleted — archival via
            // partitions, never row deletes.
            throw ImmutableRecordException::forColumn(self::class, 'row');
        });
    }

    /**
     * Restores the wire envelope from the persisted row.
     */
    public function toEnvelope(): EventEnvelope
    {
        return new EventEnvelope(
            eventId: $this->id,
            eventType: $this->event_type,
            occurredAt: $this->occurred_at->toIso8601String(),
            tenantId: (string) $this->tenant_id,
            aggregateRef: $this->aggregate_id,
            payload: $this->payload ?? [],
        );
    }

    protected function casts(): array
    {
        return [
            'schema_version' => 'integer',
            'occurred_at' => 'datetime',
            'payload' => 'array',
            'sequence' => 'integer',
            'attempt_count' => 'integer',
            'last_attempt_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }
}
