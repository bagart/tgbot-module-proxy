<?php

declare(strict_types=1);

use BAGArt\ProxyOperations\Domain\Identity\CredentialFingerprint;
use BAGArt\ProxyOperations\Domain\Identity\CredentialKind;
use BAGArt\ProxyOperations\Models\ProxyCredential;
use BAGArt\ProxyOperations\Models\ProxyEndpoint;
use BAGArt\ProxyOperations\Tenancy\TenantContext;
use BAGArt\ProxyOperations\Tenancy\TenantNotResolvedException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('creates the proxy_credentials table with expected columns and indexes', function (): void {
    expect(Schema::hasTable('proxy_credentials'))->toBeTrue()
        ->and(Schema::hasColumns('proxy_credentials', [
            'id', 'tenant_id', 'endpoint_id', 'kind', 'username',
            'secret_envelope', 'fingerprint', 'masked_representation',
            'created_at', 'updated_at',
        ]))->toBeTrue();

    $indexes = collect(DB::select("PRAGMA index_list('proxy_credentials')"));
    $uniqueIndex = $indexes->firstWhere(fn (object $index) => (bool) $index->unique
        && str_contains((string) $index->name, 'endpoint_id'));
    $fingerprintIndex = $indexes->firstWhere(fn (object $index) => ! ((bool) $index->unique)
        && str_contains((string) $index->name, 'fingerprint'));
    $endpointIndex = $indexes->firstWhere(fn (object $index) => ! ((bool) $index->unique)
        && str_contains((string) $index->name, 'endpoint_id')
        && str_contains((string) $index->name, 'tenant_id'));

    expect($uniqueIndex)->not->toBeNull()
        ->and($fingerprintIndex)->not->toBeNull()
        ->and($endpointIndex)->not->toBeNull();

    expect(collect(DB::select("PRAGMA index_info('{$uniqueIndex->name}')"))->pluck('name')->all())
        ->toBe(['tenant_id', 'endpoint_id', 'kind', 'username']);
});

it('creates a row within tenant context with tenant_id force-filled from the context', function (): void {
    $user = createTenantUser();
    app(TenantContext::class)->set($user->id);

    $credential = ProxyCredential::factory()->create(['username' => 'proxy.user']);

    expect($credential->tenant_id)->toBe($user->id)
        ->and($credential->kind)->toBe(CredentialKind::SocksAuth)
        ->and(array_keys($credential->secret_envelope ?? []))->toBe(['key_version', 'algorithm', 'nonce', 'ciphertext', 'tag'])
        ->and($credential->fingerprint)->toMatch('/^[0-9a-f]{64}$/')
        ->and(ProxyCredential::query()->whereKey($credential->id)->exists())->toBeTrue();
});

it('roundtrips all three kinds with a fingerprint equal to the CredentialFingerprint output', function (CredentialKind $kind): void {
    app(TenantContext::class)->set(createTenantUser()->id);

    $credential = ProxyCredential::factory()->create([
        'kind' => $kind,
        'username' => '  User.Name ',
        'secret' => 'plain-secret-material',
    ]);

    $expected = CredentialFingerprint::fromUserPass(
        ProxyCredential::fingerprintKey(),
        'user.name',
        'plain-secret-material',
    )->value;

    // Username participates normalized (trimmed, lowercased).
    expect($credential->kind)->toBe($kind)
        ->and($credential->fingerprint)->toBe($expected);
})->with([
    'socks auth' => CredentialKind::SocksAuth,
    'basic auth' => CredentialKind::BasicAuth,
    'mtproto secret' => CredentialKind::MtprotoSecret,
]);

it('produces the same fingerprint for identical credentials in two tenants (shared-cache rule)', function (): void {
    $context = app(TenantContext::class);

    $context->set(createTenantUser()->id);
    $mine = ProxyCredential::factory()->create(['username' => 'shared.user', 'secret' => 'same-pass']);

    $context->set(createTenantUser()->id);
    $theirs = ProxyCredential::factory()->create(['username' => 'SHARED.user ', 'secret' => 'same-pass']);

    expect($mine->fingerprint)->toBe($theirs->fingerprint)
        ->and(ProxyCredential::query()->count())->toBe(1);
});

it('never leaks the raw secret into the masked representation', function (CredentialKind $kind): void {
    app(TenantContext::class)->set(createTenantUser()->id);

    $credential = ProxyCredential::factory()->create([
        'kind' => $kind,
        'username' => $kind === CredentialKind::MtprotoSecret ? null : 'proxy.user',
        'secret' => 'Sup3rSecret!value',
    ]);

    expect(str_contains($credential->mask(), 'Sup3rSecret!value'))->toBeFalse()
        ->and($credential->masked_representation)->not->toContain('Sup3r');
})->with([
    'socks auth' => CredentialKind::SocksAuth,
    'basic auth' => CredentialKind::BasicAuth,
    'mtproto secret' => CredentialKind::MtprotoSecret,
]);

it('masks user/pass credentials with the endpoint identity when linked', function (): void {
    app(TenantContext::class)->set(createTenantUser()->id);

    $endpoint = ProxyEndpoint::factory()->create(['host' => '1.2.3.4', 'port' => 1080]);
    $linked = ProxyCredential::factory()->socksAuth()->create([
        'endpoint_id' => $endpoint->id,
        'username' => 'proxy.user',
    ]);
    $unlinked = ProxyCredential::factory()->socksAuth()->create(['username' => 'proxy.user']);

    expect($linked->mask())->toBe('pr***:***@1.2.3.4:1080')
        ->and($unlinked->mask())->not->toContain('@');
});

it('hides secret attributes from array and JSON serialization', function (): void {
    app(TenantContext::class)->set(createTenantUser()->id);

    $credential = ProxyCredential::factory()->create(['secret' => 'json-leak-probe']);

    $array = $credential->toArray();
    $json = (string) $credential->toJson();

    expect(array_key_exists('secret_envelope', $array))->toBeFalse()
        ->and(array_key_exists('secret', $array))->toBeFalse()
        ->and(str_contains($json, 'secret_envelope'))->toBeFalse()
        ->and(str_contains($json, 'json-leak-probe'))->toBeFalse()
        ->and(str_contains($json, 'plaintext-never-stored'))->toBeFalse();
});

it('rejects a duplicate profile within the same tenant at DB level', function (): void {
    $user = createTenantUser();
    app(TenantContext::class)->set($user->id);

    $endpoint = ProxyEndpoint::factory()->create();
    ProxyCredential::factory()->socksAuth()->create(['endpoint_id' => $endpoint->id, 'username' => 'dup.user']);
    ProxyCredential::factory()->socksAuth()->create(['endpoint_id' => $endpoint->id, 'username' => 'dup.user']);
})->throws(UniqueConstraintViolationException::class);

it('allows the same profile in a second tenant (cross-tenant case)', function (): void {
    $context = app(TenantContext::class);

    $context->set(createTenantUser()->id);
    $endpointA = ProxyEndpoint::factory()->create();
    ProxyCredential::factory()->create(['endpoint_id' => $endpointA->id, 'username' => 'same.user']);

    $context->set(createTenantUser()->id);
    $endpointB = ProxyEndpoint::factory()->create();
    ProxyCredential::factory()->create(['endpoint_id' => $endpointB->id, 'username' => 'same.user']);

    expect(ProxyCredential::query()->count())->toBe(1);
});

it('is invisible across tenants even by primary key (negative case)', function (): void {
    $context = app(TenantContext::class);

    $context->set(createTenantUser()->id);
    $foreignId = ProxyCredential::factory()->create()->id;

    $context->set(createTenantUser()->id);

    expect(ProxyCredential::query()->whereKey($foreignId)->exists())->toBeFalse();
});

it('throws on queries without a resolved tenant instead of falling back to unscoped', function (): void {
    ProxyCredential::query()->get();
})->throws(TenantNotResolvedException::class);

it('throws on creates without a resolved tenant', function (): void {
    ProxyCredential::factory()->create();
})->throws(TenantNotResolvedException::class);

it('discards the transient plaintext attribute instead of persisting it', function (): void {
    app(TenantContext::class)->set(createTenantUser()->id);

    $credential = ProxyCredential::factory()->create(['secret' => 'transient-material']);
    $rawEnvelope = (string) DB::table('proxy_credentials')->where('id', $credential->id)->value('secret_envelope');

    expect(Schema::hasColumn('proxy_credentials', 'secret'))->toBeFalse()
        ->and(Schema::hasColumn('proxy_credentials', 'password'))->toBeFalse()
        ->and($rawEnvelope)->not->toBe('')
        ->and(str_contains($rawEnvelope, 'transient-material'))->toBeFalse();
});
