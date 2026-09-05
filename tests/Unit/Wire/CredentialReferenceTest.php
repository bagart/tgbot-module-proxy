<?php

declare(strict_types=1);

use BAGArt\ProxyOperations\Wire\CredentialReference;

function wireCredentialReference(): CredentialReference
{
    return new CredentialReference(handle: 'unseal:node-7:9f8e');
}

it('round-trips through JSON', function (): void {
    expect(CredentialReference::fromJson(wireCredentialReference()->jsonSerialize()))
        ->toEqual(wireCredentialReference());
});

it('rejects an unknown schemaVersion', function (): void {
    $data = wireCredentialReference()->jsonSerialize();
    $data['schemaVersion'] = 99;

    CredentialReference::fromJson($data);
})->throws(RuntimeException::class, 'Unsupported CredentialReference schemaVersion');
