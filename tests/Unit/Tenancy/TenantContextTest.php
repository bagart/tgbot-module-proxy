<?php

declare(strict_types=1);

use BAGArt\ProxyOperations\Tenancy\TenantContext;
use BAGArt\ProxyOperations\Tenancy\TenantNotResolvedException;

it('returns the set tenant id', function (): void {
    $context = new TenantContext();

    $context->set(42);

    expect($context->id())->toBe(42)
        ->and($context->tryId())->toBe(42);
});

it('fails loudly when read before any tenant is set (fail closed)', function (): void {
    $context = new TenantContext();

    expect($context->tryId())->toBeNull()
        ->and(fn (): int => $context->id())->toThrow(TenantNotResolvedException::class);
});

it('forgets the tenant and refuses further access in the same scope', function (): void {
    $context = new TenantContext();

    $context->set(7);
    $context->forget();

    expect($context->tryId())->toBeNull()
        ->and(fn (): int => $context->id())->toThrow(TenantNotResolvedException::class);
});

it('replaces a previously set tenant within one scope', function (): void {
    $context = new TenantContext();

    $context->set(1);
    $context->set(2);

    expect($context->id())->toBe(2);
});

it('accepts zero as an explicit tenant id without treating it as unset', function (): void {
    $context = new TenantContext();

    $context->set(0);

    expect($context->id())->toBe(0)
        ->and($context->tryId())->toBe(0);
});
