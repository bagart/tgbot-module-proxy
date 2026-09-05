<?php

declare(strict_types=1);

use BAGArt\ProxyOperations\Encryption\CredentialEncryptor;
use BAGArt\ProxyOperations\Encryption\EncryptedField;
use BAGArt\ProxyOperations\Models\ProxyCredential;
use BAGArt\ProxyOperations\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

const PERSISTENCE_PLAINTEXT_PROBE = 'PERSIST-PLAINTEXT-xyz';

beforeEach(function (): void {
    configureTestKek('proxy-enc-test-kek-v1', 'k1');
    app(TenantContext::class)->set(createTenantUser()->id);
});

it('persists the encrypted envelope while plaintext never reaches the DB row', function (): void {
    $tenantId = app(TenantContext::class)->id();
    $credential = ProxyCredential::factory()->create([
        'username' => 'persist.user',
        'secret' => PERSISTENCE_PLAINTEXT_PROBE,
    ]);

    $raw = DB::table('proxy_credentials')->where('id', $credential->id)->first();
    $rawRowJson = (string) json_encode($raw);
    $envelope = json_decode((string) $raw->secret_envelope, true);

    expect($raw->secret_envelope)->not->toBeNull()
        ->and($envelope)->toBeArray()
        ->and(array_keys($envelope))->toBe(['key_version', 'algorithm', 'nonce', 'ciphertext', 'tag'])
        ->and(str_contains($rawRowJson, PERSISTENCE_PLAINTEXT_PROBE))->toBeFalse();

    expect(app(CredentialEncryptor::class)->decrypt(
        $tenantId,
        EncryptedField::fromJson($envelope),
    ))->toBe(PERSISTENCE_PLAINTEXT_PROBE);
});

it('keeps fingerprint and mask derivation on plaintext before it is discarded', function (): void {
    $credential = ProxyCredential::factory()->create([
        'username' => 'derive.user',
        'secret' => PERSISTENCE_PLAINTEXT_PROBE,
    ]);

    expect($credential->fingerprint)->toMatch('/^[0-9a-f]{64}$/')
        ->and($credential->mask())->toBe('de***:***')
        ->and(str_contains($credential->toJson(), PERSISTENCE_PLAINTEXT_PROBE))->toBeFalse()
        ->and(array_key_exists('secret_envelope', $credential->toArray()))->toBeFalse();
});

it('stores one DEK row per workspace and never leaks envelope material through serialization', function (): void {
    $context = app(TenantContext::class);

    $firstTenantId = $context->id();
    ProxyCredential::factory()->create(['secret' => 'first-workspace-secret']);
    $secondTenantId = createTenantUser()->id;
    $context->set($secondTenantId);
    ProxyCredential::factory()->create(['secret' => 'second-workspace-secret']);

    expect(DB::table('proxy_workspace_deks')->count())->toBe(2)
        ->and(DB::table('proxy_workspace_deks')->where('tenant_id', $firstTenantId)->count())->toBe(1)
        ->and(DB::table('proxy_workspace_deks')->where('tenant_id', $secondTenantId)->count())->toBe(1)
        ->and(str_contains(json_encode(ProxyCredential::query()->get()), 'second-workspace-secret'))->toBeFalse()
        ->and(str_contains(json_encode(DB::table('proxy_credentials')->get()), 'second-workspace-secret'))->toBeFalse();
});
