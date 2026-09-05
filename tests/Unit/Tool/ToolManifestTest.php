<?php

declare(strict_types=1);

use BAGArt\ProxyOperations\Tool\ToolLimits;
use BAGArt\ProxyOperations\Tool\ToolManifest;
use BAGArt\ProxyOperations\Tool\ToolSecurity;

function toolManifestJson(): array
{
    return toolManifest()->jsonSerialize();
}

it('round-trips through JSON', function (): void {
    expect(ToolManifest::fromJson(toolManifestJson()))->toEqual(toolManifest());
});

it('rejects an unknown schemaVersion', function (): void {
    $data = toolManifestJson();
    $data['schemaVersion'] = 99;

    ToolManifest::fromJson($data);
})->throws(RuntimeException::class, 'Unsupported ToolManifest schemaVersion');

dataset('missing manifest key', [
    ['name'],
    ['version'],
    ['apiVersion'],
    ['capabilities'],
    ['limits'],
    ['security'],
]);

it('rejects manifests missing required keys', function (string $key): void {
    $data = toolManifestJson();
    unset($data[$key]);

    ToolManifest::fromJson($data);
})->with('missing manifest key')->throws(InvalidArgumentException::class);

it('rejects non-positive limits', function (): void {
    new ToolLimits(maxExecutionTimeSeconds: 0, maxOutputBytes: 1024);
})->throws(InvalidArgumentException::class, 'maxExecutionTime must be a positive number of seconds');

it('rejects non-positive output byte caps', function (): void {
    new ToolLimits(maxExecutionTimeSeconds: 30, maxOutputBytes: 0);
})->throws(InvalidArgumentException::class, 'maxOutputBytes must be positive');

it('rejects a manifest with negative apiVersion', function (): void {
    new ToolManifest(
        name: toolId(),
        version: '1.0.0',
        apiVersion: -1,
        capabilities: toolCapabilities(),
        limits: new ToolLimits(maxExecutionTimeSeconds: 30, maxOutputBytes: 1),
        security: new ToolSecurity(network: 'outbound-only', filesystem: 'readonly', privileges: 'none'),
    );
})->throws(InvalidArgumentException::class, 'apiVersion must be >= 1');
