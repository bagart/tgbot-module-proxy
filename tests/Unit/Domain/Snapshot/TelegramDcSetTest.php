<?php

declare(strict_types=1);

use BAGArt\ProxyOperations\Domain\Snapshot\TelegramDc;
use BAGArt\ProxyOperations\Domain\Snapshot\TelegramDcSet;

function makeDcSet(int $version = 2): TelegramDcSet
{
    return new TelegramDcSet(
        version: $version,
        dcs: [
            new TelegramDc(dcId: 2, addresses: ['149.154.167.50'], ports: [443], enabled: true),
            new TelegramDc(dcId: 4, addresses: ['149.154.167.91'], ports: [443, 80], enabled: false),
        ],
        frozenAt: '2026-08-26T00:00:00+00:00',
    );
}

it('round-trips through JSON', function (): void {
    $snapshot = makeDcSet();

    $restored = TelegramDcSet::fromJson($snapshot->jsonSerialize());

    expect($restored)->toEqual($snapshot);
});

it('requires the version field on deserialization', function (): void {
    $data = makeDcSet()->jsonSerialize();
    unset($data['version']);

    TelegramDcSet::fromJson($data);
})->throws(RuntimeException::class, 'mandatory version');

it('rejects unsupported schema versions', function (): void {
    TelegramDcSet::fromJson(['schemaVersion' => 7, 'version' => 1]);
})->throws(RuntimeException::class, 'schemaVersion');
