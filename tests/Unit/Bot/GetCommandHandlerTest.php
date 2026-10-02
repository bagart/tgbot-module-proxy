<?php

declare(strict_types=1);

use BAGArt\ProxyOperations\Bot\BotCommandContext;
use BAGArt\ProxyOperations\Bot\GetCommandHandler;
use BAGArt\ProxyOperations\Tenancy\TenantContext;

it('returns usage when no arguments', function (): void {
    $handler = new GetCommandHandler(new TenantContext());

    $result = $handler->handle(new BotCommandContext(
        tenantId: '1',
        chatId: '100',
        userId: '200',
        command: 'get',
        arguments: '',
    ));

    expect($result['text'])->toContain('Usage');
});

it('returns error message for unknown proxy', function (): void {
    $handler = new GetCommandHandler(new TenantContext());

    $result = $handler->handle(new BotCommandContext(
        tenantId: '1',
        chatId: '100',
        userId: '200',
        command: 'get',
        arguments: 'nonexistent-id',
    ));

    expect($result['text'])->not->toBeEmpty();
});

it('handles returns get', function (): void {
    $handler = new GetCommandHandler(new TenantContext());
    expect($handler->handles())->toBe('get');
});
