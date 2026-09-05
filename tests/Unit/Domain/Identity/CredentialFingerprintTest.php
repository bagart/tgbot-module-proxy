<?php

declare(strict_types=1);

use BAGArt\ProxyOperations\Domain\Identity\CredentialFingerprint;

const TEST_KEY = "\xa3\x9f\x01binary-key-bytes\xff";

it('is stable for identical normalized credentials', function (): void {
    $first = CredentialFingerprint::fromUserPass(TEST_KEY, 'user', 'secret');
    $second = CredentialFingerprint::fromUserPass(TEST_KEY, 'user', 'secret');

    expect($first->value)->toBe($second->value)
        ->and($first->value)->toMatch('/^[0-9a-f]{64}$/');
});

it('normalizes username case and surrounding whitespace but keeps password raw', function (): void {
    $base = CredentialFingerprint::fromUserPass(TEST_KEY, 'user', 'secret');

    expect(CredentialFingerprint::fromUserPass(TEST_KEY, 'USER', 'secret')->value)
        ->toBe($base->value)
        ->and(CredentialFingerprint::fromUserPass(TEST_KEY, ' user ', 'secret')->value)
        ->toBe($base->value)
        ->and(CredentialFingerprint::fromUserPass(TEST_KEY, 'user', 'Secret')->value)
        ->not->toBe($base->value)
        ->and(CredentialFingerprint::fromUserPass(TEST_KEY, 'user', ' secret')->value)
        ->not->toBe($base->value);
});

it('changes with the injected key', function (): void {
    $fingerprint = CredentialFingerprint::fromUserPass(TEST_KEY, 'user', 'secret');

    expect(CredentialFingerprint::fromUserPass(str_repeat('k', 32), 'user', 'secret')->value)
        ->not->toBe($fingerprint->value);
});

it('never collides across different credential splits', function (): void {
    $splitOne = CredentialFingerprint::fromUserPass(TEST_KEY, 'a', 'bc');
    $splitTwo = CredentialFingerprint::fromUserPass(TEST_KEY, 'ab', 'c');
    $swapped = CredentialFingerprint::fromUserPass(TEST_KEY, 'bc', 'a');

    expect($splitTwo->value)->not->toBe($splitOne->value)
        ->and($swapped->value)->not->toBe($splitOne->value);
});

it('never exposes source credentials in its value', function (): void {
    $fingerprint = CredentialFingerprint::fromUserPass(TEST_KEY, 'superuser', 'top-secret-password');

    expect(str_contains($fingerprint->value, 'superuser'))->toBeFalse()
        ->and(str_contains($fingerprint->value, 'top-secret-password'))->toBeFalse()
        ->and($fingerprint->jsonSerialize()['value'])->toBe($fingerprint->value);
});

it('round-trips through JSON', function (): void {
    $fingerprint = CredentialFingerprint::fromUserPass(TEST_KEY, 'user', 'secret');
    $restored = CredentialFingerprint::fromJson(json_decode(json_encode($fingerprint), true));

    expect($restored->value)->toBe($fingerprint->value);
});

it('rejects unknown schema versions and malformed values', function (array $payload): void {
    CredentialFingerprint::fromJson($payload);
})->with([
    [['value' => str_repeat('a', 64), 'schemaVersion' => 99]],
    [['value' => 'not-hex', 'schemaVersion' => 1]],
    [['value' => str_repeat('A', 64), 'schemaVersion' => 1]],
])->throws(RuntimeException::class);
