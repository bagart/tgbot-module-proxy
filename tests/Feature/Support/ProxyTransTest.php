<?php

declare(strict_types=1);

use BAGArt\ProxyOperations\Support\ProxyTrans;

it('loads English translation by default', function (): void {
    $result = ProxyTrans::get('proxy.bot.start');

    expect($result)->not->toBeEmpty()
        ->and($result)->toContain('Proxy Operations');
});

it('resolves error code translations', function (): void {
    $result = ProxyTrans::error('PROXY_INVALID_PORT');

    expect($result)->not->toBeEmpty();
});

it('returns key on missing translation', function (): void {
    $result = ProxyTrans::get('proxy.nonexistent.key');

    expect($result)->toBe('proxy.nonexistent.key');
});

it('applies replacement parameters', function (): void {
    $result = ProxyTrans::get('proxy.import.success', ['imported' => 5, 'skipped' => 2]);

    expect($result)->toContain('5')
        ->and($result)->toContain('2');
});
