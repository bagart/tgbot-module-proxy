<?php

declare(strict_types=1);

use BAGArt\ProxyOperations\Wire\SealedCredentialPayload;

function wireSealedCredentialPayload(): SealedCredentialPayload
{
    return new SealedCredentialPayload(
        algId: 'aes-256-gcm',
        ciphertext: base64_encode('ciphertext-bytes'),
        nonce: base64_encode('nonce-bytes'),
    );
}

it('round-trips through JSON without exposing plaintext fields', function (): void {
    $payload = wireSealedCredentialPayload();

    expect(SealedCredentialPayload::fromJson($payload->jsonSerialize()))->toEqual($payload)
        ->and(array_keys($payload->jsonSerialize()))->toEqual(['algId', 'ciphertext', 'nonce', 'schemaVersion']);
});

it('rejects an unknown schemaVersion', function (): void {
    $data = wireSealedCredentialPayload()->jsonSerialize();
    $data['schemaVersion'] = 99;

    SealedCredentialPayload::fromJson($data);
})->throws(RuntimeException::class, 'Unsupported SealedCredentialPayload schemaVersion');
