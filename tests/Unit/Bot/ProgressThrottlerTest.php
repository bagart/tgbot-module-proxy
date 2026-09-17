<?php

declare(strict_types=1);

use BAGArt\ProxyOperations\Bot\ProgressThrottler;

it('returns zero delay for first message', function (): void {
    $throttler = new ProgressThrottler;
    expect($throttler->delayNeeded(1))->toBe(0);
});

it('returns delay needed after recent message', function (): void {
    $throttler = new ProgressThrottler;
    $throttler->recordSend(1);
    $delay = $throttler->delayNeeded(1);
    expect($delay)->toBeGreaterThanOrEqual(0)
        ->and($delay)->toBeLessThanOrEqual(50);
});

it('clears tracking for a chat', function (): void {
    $throttler = new ProgressThrottler;
    $throttler->recordSend(42);
    $throttler->clear(42);
    expect($throttler->delayNeeded(42))->toBe(0);
});

it('tracks different chats independently', function (): void {
    $throttler = new ProgressThrottler;
    $throttler->recordSend(1);
    expect($throttler->delayNeeded(2))->toBe(0);
});
