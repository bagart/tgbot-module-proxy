<?php

declare(strict_types=1);

use BAGArt\ProxyOperations\Encryption\EncryptedField;

it('serializes to exactly the five envelope keys with base64-encoded binary members', function (): void {
    $field = new EncryptedField('k1', 'aes-256-gcm', random_bytes(12), 'cipher-bytes', random_bytes(16));

    $json = $field->jsonSerialize();

    expect(array_keys($json))->toBe(['key_version', 'algorithm', 'nonce', 'ciphertext', 'tag'])
        ->and($json['key_version'])->toBe('k1')
        ->and($json['algorithm'])->toBe('aes-256-gcm')
        ->and($json['nonce'])->toBe(base64_encode($field->nonce))
        ->and($json['ciphertext'])->toBe(base64_encode('cipher-bytes'))
        ->and($json['tag'])->toBe(base64_encode($field->tag));
});

it('roundtrips through JSON without losing bytes', function (): void {
    $field = new EncryptedField('k7', 'aes-256-gcm', random_bytes(12), random_bytes(64), random_bytes(16));

    $restored = EncryptedField::fromJson(json_decode(json_encode($field), true));

    expect($restored->keyVersion)->toBe($field->keyVersion)
        ->and($restored->algorithm)->toBe($field->algorithm)
        ->and($restored->nonce)->toBe($field->nonce)
        ->and($restored->ciphertext)->toBe($field->ciphertext)
        ->and($restored->tag)->toBe($field->tag)
        ->and($restored)->not->toBe($field);
});

it('rejects an unknown schema version on deserialize (negative case)', function (): void {
    $payload = (new EncryptedField('k1', 'aes-256-gcm', random_bytes(12), 'c', random_bytes(16)))->jsonSerialize();
    $payload['schemaVersion'] = 99;

    EncryptedField::fromJson($payload);
})->throws(RuntimeException::class, 'Unsupported EncryptedField schemaVersion');

it('accepts a payload without an explicit schema version as V1', function (): void {
    $payload = (new EncryptedField('k1', 'aes-256-gcm', random_bytes(12), 'c', random_bytes(16)))->jsonSerialize();

    expect(EncryptedField::fromJson($payload)->nonce)->toBeString();
});

it('rejects malformed base64 in any binary member (negative case)', function (): void {
    $valid = fn (): array => (new EncryptedField('k1', 'aes-256-gcm', random_bytes(12), 'c', random_bytes(16)))->jsonSerialize();

    foreach (['nonce', 'ciphertext', 'tag'] as $member) {
        $payload = $valid();
        $payload[$member] = '!!!not-base64!!!';

        try {
            EncryptedField::fromJson($payload);
            $this->fail(sprintf('Expected a RuntimeException for member "%s".', $member));
        } catch (RuntimeException) {
        }
    }

    expect(true)->toBeTrue();
});
