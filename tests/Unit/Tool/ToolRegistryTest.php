<?php

declare(strict_types=1);

use BAGArt\ProxyOperations\Tool\ToolId;
use BAGArt\ProxyOperations\Tool\ToolManifest;
use BAGArt\ProxyOperations\Tool\ToolRegistry;
use BAGArt\ProxyOperations\Tool\UnknownToolException;

it('resolves an allowlisted tool id to its manifest', function (): void {
    $registry = new ToolRegistry([toolId()->value => toolManifest()]);

    expect($registry->resolve(toolId()))->toBeInstanceOf(ToolManifest::class);
});

it('rejects a tool id outside the allowlist', function (): void {
    (new ToolRegistry([toolId()->value => toolManifest()]))->resolve(new ToolId('mtproto-checker'));
})->throws(UnknownToolException::class, "Unknown tool 'mtproto-checker'");

it('rejects an empty allowlist resolution without any fallback', function (): void {
    (new ToolRegistry([]))->resolve(toolId());
})->throws(UnknownToolException::class);

it('enforces registry keys to match manifest names', function (): void {
    new ToolRegistry(['other-name' => toolManifest()]);
})->throws(InvalidArgumentException::class, "Registry key 'other-name' must match the manifest name");

it('publishes all manifests for GET /capabilities', function (): void {
    expect((new ToolRegistry([toolId()->value => toolManifest()]))->manifests())
        ->toHaveCount(1)
        ->each->toBeInstanceOf(ToolManifest::class);
});
