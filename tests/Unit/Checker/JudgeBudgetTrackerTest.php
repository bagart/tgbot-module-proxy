<?php

declare(strict_types=1);

use BAGArt\ProxyOperations\Checker\JudgeBudgetConfig;
use BAGArt\ProxyOperations\Checker\JudgeBudgetTracker;

it('allows first request within budget', function (): void {
    $tracker = new JudgeBudgetTracker(new JudgeBudgetConfig(rateLimitPerMinute: 5, windowSeconds: 60));

    expect($tracker->allow('judge-1'))->toBeTrue();
});

it('blocks burst exceeding rateLimitPerMinute', function (): void {
    $tracker = new JudgeBudgetTracker(new JudgeBudgetConfig(rateLimitPerMinute: 2, windowSeconds: 60));

    expect($tracker->allow('judge-1'))->toBeTrue()
        ->and($tracker->allow('judge-1'))->toBeTrue()
        ->and($tracker->allow('judge-1'))->toBeFalse();
});

it('restores budget as old requests expire', function (): void {
    $tracker = new JudgeBudgetTracker(new JudgeBudgetConfig(rateLimitPerMinute: 1, windowSeconds: 1));

    expect($tracker->allow('judge-1'))->toBeTrue()
        ->and($tracker->allow('judge-1'))->toBeFalse();

    sleep(2);

    expect($tracker->allow('judge-1'))->toBeTrue();
});

it('tracks multiple judges independently', function (): void {
    $tracker = new JudgeBudgetTracker(new JudgeBudgetConfig(rateLimitPerMinute: 1, windowSeconds: 60));

    expect($tracker->allow('judge-1'))->toBeTrue()
        ->and($tracker->allow('judge-1'))->toBeFalse()
        ->and($tracker->allow('judge-2'))->toBeTrue();
});

it('reports remaining budget correctly', function (): void {
    $tracker = new JudgeBudgetTracker(new JudgeBudgetConfig(rateLimitPerMinute: 3, windowSeconds: 60));

    expect($tracker->remaining('judge-1'))->toBe(3);

    $tracker->allow('judge-1');

    expect($tracker->remaining('judge-1'))->toBe(2);

    $tracker->allow('judge-1');
    $tracker->allow('judge-1');

    expect($tracker->remaining('judge-1'))->toBe(0);
});

it('does not block different judges when one is exhausted', function (): void {
    $tracker = new JudgeBudgetTracker(new JudgeBudgetConfig(rateLimitPerMinute: 1, windowSeconds: 60));

    $tracker->allow('judge-1');

    expect($tracker->allow('judge-2'))->toBeTrue();
});
