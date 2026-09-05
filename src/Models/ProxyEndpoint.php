<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Models;

use BAGArt\ProxyOperations\Database\Factories\ProxyEndpointFactory;
use BAGArt\ProxyOperations\Domain\Identity\EndpointCanonicalizer;
use BAGArt\ProxyOperations\Domain\Identity\EndpointIdentity;
use BAGArt\ProxyOperations\Domain\Identity\ProxyProtocol;
use BAGArt\ProxyOperations\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Central inventory entity: network identity (protocol/host/port) as imported,
 * plus the canonical form and its deterministic hash (plan §§5, 11.2).
 *
 * INV-002: no canonical health/capability/lifecycle fields here — those live on
 * ProxyAccess; this model only accepts ProxyProtocol values.
 *
 * @property string $id
 * @property int $tenant_id
 * @property ProxyProtocol $protocol
 * @property string $host
 * @property int $port
 * @property string $canonical_host
 * @property string $endpoint_identity_hash
 * @property string|null $comment
 */
final class ProxyEndpoint extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'protocol',
        'host',
        'port',
        'comment',
    ];

    protected static function booted(): void
    {
        self::creating(function (self $endpoint): void {
            $identity = (new EndpointCanonicalizer)->canonicalize(
                host: $endpoint->host,
                port: $endpoint->port,
                protocol: $endpoint->protocol,
            );

            $endpoint->forceFill([
                'canonical_host' => $identity->host,
                'port' => $identity->port,
                'endpoint_identity_hash' => self::identityHash($identity),
            ]);
        });
    }

    public function identity(): EndpointIdentity
    {
        return new EndpointIdentity(
            host: $this->canonical_host,
            port: $this->port,
            protocol: $this->protocol,
        );
    }

    /**
     * Preferred creation path for callers that already hold a canonical
     * EndpointIdentity — they never hand-roll canonicalization or hashing.
     */
    public static function fromIdentity(EndpointIdentity $identity, ?string $originalHost = null, ?string $comment = null): self
    {
        return (new self)->fill([
            'protocol' => $identity->protocol,
            'host' => $originalHost ?? $identity->host,
            'port' => $identity->port,
            'comment' => $comment,
        ]);
    }

    /**
     * Tenant-independent deterministic hash of the canonical identity;
     * tenant-scoping of the uniqueness comes from the composite unique index.
     */
    public static function identityHash(EndpointIdentity $identity): string
    {
        return hash('sha256', $identity->protocol->value."\x00".$identity->host."\x00".$identity->port);
    }

    public function accesses(): HasMany
    {
        return $this->hasMany(ProxyAccess::class);
    }

    protected function casts(): array
    {
        return [
            'protocol' => ProxyProtocol::class,
            'port' => 'integer',
        ];
    }

    protected static function newFactory(): Factory
    {
        return ProxyEndpointFactory::new();
    }
}
