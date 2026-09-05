<?php

declare(strict_types=1);

use App\Models\User;
use BAGArt\ProxyOperations\Domain\Identity\EndpointCanonicalizer;
use BAGArt\ProxyOperations\Domain\Identity\ProxyProtocol;
use BAGArt\ProxyOperations\Models\ProxyEndpoint;
use BAGArt\ProxyOperations\Tenancy\TenantContext;
use BAGArt\ProxyOperations\Tenancy\TenantNotResolvedException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function createTenantUser(): User
{
    return User::factory()->create();
}

it('creates the proxy_endpoints table with expected columns and indexes', function (): void {
    expect(Schema::hasTable('proxy_endpoints'))->toBeTrue()
        ->and(Schema::hasColumns('proxy_endpoints', [
            'id', 'tenant_id', 'protocol', 'host', 'port',
            'canonical_host', 'endpoint_identity_hash', 'comment',
            'created_at', 'updated_at',
        ]))->toBeTrue();

    $indexes = collect(DB::select("PRAGMA index_list('proxy_endpoints')"));
    $uniqueIndex = $indexes->firstWhere(fn (object $index) => (bool) $index->unique
        && str_contains((string) $index->name, 'endpoint_identity_hash'));
    $protocolIndex = $indexes->firstWhere(fn (object $index) => ! ((bool) $index->unique)
        && str_contains((string) $index->name, 'protocol'));

    expect($uniqueIndex)->not->toBeNull()
        ->and($protocolIndex)->not->toBeNull();

    $uniqueColumns = collect(DB::select("PRAGMA index_info('{$uniqueIndex->name}')"))
        ->pluck('name')->all();

    expect($uniqueColumns)->toBe(['tenant_id', 'endpoint_identity_hash']);
});

it('creates a row within tenant context with tenant_id force-filled from the context', function (): void {
    $user = createTenantUser();
    app(TenantContext::class)->set($user->id);

    $endpoint = ProxyEndpoint::factory()->create();

    expect($endpoint->tenant_id)->toBe($user->id)
        ->and($endpoint->protocol)->toBe(ProxyProtocol::Socks5)
        ->and($endpoint->port)->toBeInt()
        ->and(ProxyEndpoint::query()->whereKey($endpoint->id)->exists())->toBeTrue();
});

it('stores a canonical hash equal to the EndpointCanonicalizer output for equivalent inputs', function (): void {
    $canonicalizer = new EndpointCanonicalizer;
    $user = createTenantUser();
    app(TenantContext::class)->set($user->id);

    foreach (['1.2.3.4', '1.2.3.4.', 'EXAMPLE.com', 'example.COM.'] as $host) {
        $canonical = $canonicalizer->canonicalize($host, 1080, ProxyProtocol::Socks5);
        // separate tenants per input: identical identities must not collide
        app(TenantContext::class)->set(createTenantUser()->id);
        $endpoint = ProxyEndpoint::factory()->create(['host' => $host]);

        expect($endpoint->canonical_host)->toBe($canonical->host)
            ->and($endpoint->endpoint_identity_hash)->toBe(ProxyEndpoint::identityHash($canonical));
    }
});

it('rejects a duplicate identity within the same tenant at DB level', function (): void {
    $user = createTenantUser();
    app(TenantContext::class)->set($user->id);

    ProxyEndpoint::factory()->create(['host' => '1.2.3.4', 'port' => 1080]);
    ProxyEndpoint::factory()->create(['host' => '1.2.3.4.', 'port' => 1080]);
})->throws(UniqueConstraintViolationException::class);

it('allows the same identity in a second tenant (cross-tenant case)', function (): void {
    $context = app(TenantContext::class);

    $context->set(createTenantUser()->id);
    $mine = ProxyEndpoint::factory()->create(['host' => 'proxy.example.com']);

    $context->set(createTenantUser()->id);
    $theirs = ProxyEndpoint::factory()->create(['host' => 'PROXY.example.com.']);

    expect(ProxyEndpoint::query()->count())->toBe(1)
        ->and($theirs->endpoint_identity_hash)->toBe($mine->endpoint_identity_hash)
        ->and($theirs->canonical_host)->toBe($mine->canonical_host);
});

it('throws on queries without a resolved tenant instead of falling back to unscoped', function (): void {
    ProxyEndpoint::query()->get();
})->throws(TenantNotResolvedException::class);

it('throws on creates without a resolved tenant', function (): void {
    ProxyEndpoint::factory()->create(['host' => 'orphan.example.com']);
})->throws(TenantNotResolvedException::class);

it('returns an EndpointIdentity equal to the canonicalized input', function (): void {
    $user = createTenantUser();
    app(TenantContext::class)->set($user->id);

    $endpoint = ProxyEndpoint::factory()->create(['host' => 'Example.COM', 'port' => 1080]);
    $expected = (new EndpointCanonicalizer)->canonicalize('example.com', 1080, ProxyProtocol::Socks5);

    expect($endpoint->identity())->toEqual($expected)
        ->and($endpoint->identity()->equals($expected))->toBeTrue()
        ->and($endpoint->identity()->toString())->toBe('socks5://example.com');
});
