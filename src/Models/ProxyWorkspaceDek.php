<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Storage row for one workspace Data Encryption Key (task T09, plan §11.23):
 * holds only the KEK-wrapped DEK envelope — never clear key material.
 * Deliberately without BelongsToTenant: this table has no user-facing surface
 * and all access goes through CredentialEncryptor with an explicit tenant_id
 * argument (the encryptor may legitimately run outside a resolved tenant
 * context); scoping is enforced by the unique tenant_id index plus the
 * encryptor's always-explicit where-clauses.
 *
 * @property string $id
 * @property int $tenant_id
 * @property array<string, mixed> $wrapped_dek
 * @property string $key_version
 * @property Carbon|null $created_at
 * @property Carbon|null $rotated_at
 */
final class ProxyWorkspaceDek extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'wrapped_dek',
        'key_version',
        'created_at',
        'rotated_at',
    ];

    protected $hidden = [
        'wrapped_dek',
    ];

    protected function casts(): array
    {
        return [
            'wrapped_dek' => 'array',
            'created_at' => 'datetime',
            'rotated_at' => 'datetime',
        ];
    }
}
