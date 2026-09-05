<?php

declare(strict_types=1);

use BAGArt\ProxyOperations\Encryption\ConfigKekProvider;
use BAGArt\ProxyOperations\Encryption\CredentialEncryptor;
use BAGArt\ProxyOperations\Encryption\EncryptedField;
use BAGArt\ProxyOperations\Encryption\KekProvider;
use BAGArt\ProxyOperations\Models\ProxyWorkspaceDek;
use Illuminate\Contracts\Config\Repository;
use RuntimeException;

beforeEach(function (): void {
    configureTestKek('proxy-enc-test-kek-v1', 'k1');
});

it('roundtrips a secret through the per-workspace DEK', function (): void {
    $encryptor = app(CredentialEncryptor::class);
    $tenantId = createTenantUser()->id;

    $field = $encryptor->encrypt($tenantId, 'round-trip-secret-material');

    expect($field->keyVersion)->toBe('k1')
        ->and($field->algorithm)->toBe('aes-256-gcm')
        ->and(strlen($field->nonce))->toBe(12)
        ->and(strlen($field->tag))->toBe(16)
        ->and(ProxyWorkspaceDek::query()->where('tenant_id', $tenantId)->count())->toBe(1)
        ->and($encryptor->decrypt($tenantId, $field))->toBe('round-trip-secret-material');
});

it('reuses one DEK row per tenant and produces a fresh nonce on every encryption', function (): void {
    $encryptor = app(CredentialEncryptor::class);
    $tenantId = createTenantUser()->id;

    $first = $encryptor->encrypt($tenantId, 'same-plaintext');
    $second = $encryptor->encrypt($tenantId, 'same-plaintext');

    expect(ProxyWorkspaceDek::query()->where('tenant_id', $tenantId)->count())->toBe(1)
        ->and($second->keyVersion)->toBe($first->keyVersion)
        ->and($second->nonce === $first->nonce)->toBeFalse('Nonce must never repeat across encryptions.')
        ->and($second->ciphertext === $first->ciphertext)->toBeFalse();
});

it('keeps workspaces cryptographically isolated (cross-tenant negative case)', function (): void {
    $encryptor = app(CredentialEncryptor::class);
    $mineTenantId = createTenantUser()->id;
    $theirTenantId = createTenantUser()->id;

    $mine = $encryptor->encrypt($mineTenantId, 'tenant-one-secret');
    $theirs = $encryptor->encrypt($theirTenantId, 'tenant-two-secret');

    expect(ProxyWorkspaceDek::query()->count())->toBe(2)
        ->and($encryptor->decrypt($theirTenantId, $theirs))->toBe('tenant-two-secret')
        ->and(fn () => $encryptor->decrypt($theirTenantId, $mine))->toThrow(RuntimeException::class, 'Credential envelope failed authentication.');
});

it('fails authentication on tampered ciphertext, nonce or tag with no partial output', function (): void {
    $encryptor = app(CredentialEncryptor::class);
    $tenantId = createTenantUser()->id;
    $field = $encryptor->encrypt($tenantId, 'tamper-target-secret');

    foreach ([
        'ciphertext' => envelopeFlipByte($field->ciphertext),
        'nonce' => envelopeFlipByte($field->nonce),
        'tag' => envelopeFlipByte($field->tag),
    ] as $member => $corrupted) {
        $tampered = new EncryptedField(
            $field->keyVersion,
            $field->algorithm,
            $member === 'nonce' ? $corrupted : $field->nonce,
            $member === 'ciphertext' ? $corrupted : $field->ciphertext,
            $member === 'tag' ? $corrupted : $field->tag,
        );

        expect(fn () => $encryptor->decrypt($tenantId, $tampered))->toThrow(RuntimeException::class);
    }
});

it('rewraps the workspace DEK after a KEK version bump keeping old fields decryptable', function (): void {
    $encryptor = app(CredentialEncryptor::class);
    $tenantId = createTenantUser()->id;
    $beforeRotation = $encryptor->encrypt($tenantId, 'survives-the-rotation');

    configureTestKek('proxy-enc-test-kek-v2', 'k2', ['k1' => 'proxy-enc-test-kek-v1']);
    $encryptor->rewrapDek($tenantId);

    $row = ProxyWorkspaceDek::query()->where('tenant_id', $tenantId)->firstOrFail();
    $wrapped = EncryptedField::fromJson($row->wrapped_dek);

    expect($row->key_version)->toBe('k2')
        ->and($wrapped->keyVersion)->toBe('k2')
        ->and($row->rotated_at)->not->toBeNull()
        ->and($encryptor->decrypt($tenantId, $beforeRotation))->toBe('survives-the-rotation');

    $afterRotation = $encryptor->encrypt($tenantId, 'post-rotation');

    expect($afterRotation->keyVersion)->toBe('k2')
        ->and($encryptor->decrypt($tenantId, $afterRotation))->toBe('post-rotation');
});

it('refuses to rewrap when no DEK row exists yet (negative case)', function (): void {
    app(CredentialEncryptor::class)->rewrapDek(999999);
})->throws(RuntimeException::class, 'No workspace DEK exists for tenant 999999.');

it('rejects an unsupported configured algorithm at construction (negative case)', function (): void {
    config()->set('proxy-operations.encryption.algorithm', 'aes-128-cbc');

    new CredentialEncryptor(app(KekProvider::class), app(Repository::class));
})->throws(RuntimeException::class, 'Unsupported credential envelope algorithm "aes-128-cbc"');

it('exposes the KEK provider as the ConfigKekProvider singleton', function (): void {
    expect(app(KekProvider::class))->toBeInstanceOf(ConfigKekProvider::class);
});

function configureTestKek(string $material, string $version, array $historical = []): void
{
    config()->set([
        'proxy-operations.encryption.kek' => $material,
        'proxy-operations.encryption.key_version' => $version,
        'proxy-operations.encryption.historical_keks' => $historical,
    ]);
}

function envelopeFlipByte(string $value): string
{
    $copy = $value;
    $copy[0] = $copy[0] ^ "\x5A";

    return $copy;
}
