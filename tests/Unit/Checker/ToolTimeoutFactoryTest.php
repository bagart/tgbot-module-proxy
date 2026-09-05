<?php

declare(strict_types=1);

use BAGArt\ProxyOperations\Checker\ToolTimeoutFactory;
use BAGArt\ProxyOperations\Domain\Probe\TimeoutTier;

it('maps the Aggressive tier to 5000 ms', function (): void {
    expect((new ToolTimeoutFactory)->timeoutFor(TimeoutTier::Aggressive))->toBe(5000);
});

it('maps the Standard tier to 15000 ms', function (): void {
    expect((new ToolTimeoutFactory)->timeoutFor(TimeoutTier::Standard))->toBe(15000);
});

it('maps the Generous tier to 30000 ms', function (): void {
    expect((new ToolTimeoutFactory)->timeoutFor(TimeoutTier::Generous))->toBe(30000);
});
