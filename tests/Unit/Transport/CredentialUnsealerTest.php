<?php

declare(strict_types=1);

use BAGArt\ProxyOperations\Transport\CredentialDecryptor;
use BAGArt\ProxyOperations\Transport\CredentialPayload;
use BAGArt\ProxyOperations\Transport\CredentialUnsealer;
use BAGArt\ProxyOperations\Wire\SealedCredentialPayload;

it('unseals a sealed envelope into CredentialPayload', function (): void {
    $secret = 'my-secret-password';
    $sealed = new SealedCredentialPayload(
        algId: 'aes-256-gcm',
        ciphertext: base64_encode($secret),
        nonce: base64_encode(random_bytes(12)),
    );

    $decryptor = new class($secret) implements CredentialDecryptor
    {
        public function __construct(
            private readonly string $expectedSecret,
        ) {}

        public function decrypt(SealedCredentialPayload $sealed): string
        {
            return $this->expectedSecret;
        }
    };

    $unsealer = new CredentialUnsealer($decryptor);
    $payload = $unsealer->unseal($sealed);

    expect($payload)->toBeInstanceOf(CredentialPayload::class);
    expect($payload->secret)->toBe($secret);
    expect($payload->username)->toBeNull();
});

it('CredentialPayload.secret matches the original value', function (): void {
    $originalSecret = 'test-secret-42';
    $sealed = new SealedCredentialPayload(
        algId: 'aes-256-gcm',
        ciphertext: base64_encode($originalSecret),
        nonce: base64_encode(random_bytes(12)),
    );

    $decryptor = new class($originalSecret) implements CredentialDecryptor
    {
        public function __construct(
            private readonly string $secret,
        ) {}

        public function decrypt(SealedCredentialPayload $sealed): string
        {
            return $this->secret;
        }
    };

    $unsealer = new CredentialUnsealer($decryptor);
    $payload = $unsealer->unseal($sealed);

    expect($payload->secret)->toBe($originalSecret);
    expect(strlen($payload->secret))->toBeGreaterThan(0);
});

it('CredentialDecryptor can be a mock', function (): void {
    $sealed = new SealedCredentialPayload(
        algId: 'aes-256-gcm',
        ciphertext: base64_encode('encrypted'),
        nonce: base64_encode(random_bytes(12)),
    );

    $decryptor = Mockery::mock(CredentialDecryptor::class);
    $decryptor->shouldReceive('decrypt')
        ->once()
        ->with($sealed)
        ->andReturn('decrypted-secret');

    $unsealer = new CredentialUnsealer($decryptor);
    $payload = $unsealer->unseal($sealed);

    expect($payload->secret)->toBe('decrypted-secret');
});
