<?php

declare(strict_types=1);

use App\Models\User;
use BAGArt\ProxyOperations\Domain\Parsing\ImportProxiesCommand;
use BAGArt\ProxyOperations\Models\ProxyEndpoint;
use BAGArt\ProxyOperations\Models\RawFeedEntry;
use BAGArt\ProxyOperations\Parser\ImportProxiesService;
use BAGArt\ProxyOperations\Tenancy\TenantContext;

function createIdempotencyUser(): User
{
    return User::factory()->create();
}

function makeIdempotencyCommand(string $text, ?string $idempotencyKey = null): ImportProxiesCommand
{
    return new ImportProxiesCommand(
        text: $text,
        sourceLabel: 'idempotency-test',
        tenantId: app(TenantContext::class)->id(),
        idempotencyKey: $idempotencyKey,
    );
}

it('second import without idempotency key creates zero new endpoints (deduped by identity hash)', function (): void {
    $user = createIdempotencyUser();
    app(TenantContext::class)->set($user->id);

    $service = app(ImportProxiesService::class);
    $first = $service->execute(makeIdempotencyCommand('socks5://u:p@1.2.3.4:1080'));
    expect($first->created)->toBe(1);

    $second = $service->execute(makeIdempotencyCommand('socks5://u:p@1.2.3.4:1080'));
    expect($second->created)->toBe(0)
        ->and($second->skipped)->toBe(1);

    expect(ProxyEndpoint::query()->count())->toBe(1);
});

it('second call with same idempotency key returns cached ImportResult', function (): void {
    $user = createIdempotencyUser();
    app(TenantContext::class)->set($user->id);

    $service = app(ImportProxiesService::class);
    $first = $service->execute(makeIdempotencyCommand('1.2.3.4:1080', 'idem-key-1'));

    $second = $service->execute(makeIdempotencyCommand('1.2.3.4:1080', 'idem-key-1'));

    expect($second->importBatchId)->toBe($first->importBatchId)
        ->and($second->created)->toBe($first->created)
        ->and($second->skipped)->toBe($first->skipped);
});

it('different idempotency keys with same text return same batch (dedup by batch hash wins)', function (): void {
    $user = createIdempotencyUser();
    app(TenantContext::class)->set($user->id);

    $service = app(ImportProxiesService::class);
    $first = $service->execute(makeIdempotencyCommand('1.2.3.4:1080', 'key-a'));

    $second = $service->execute(makeIdempotencyCommand('1.2.3.4:1080', 'key-b'));

    expect($first->importBatchId)->toBe($second->importBatchId)
        ->and($second->created)->toBe(0)
        ->and($second->skipped)->toBe(1);

    expect(ProxyEndpoint::query()->count())->toBe(1);
});

it('RawFeedEntry unique constraint prevents duplicate (batch_hash, line_number) within same tenant', function (): void {
    $user = createIdempotencyUser();
    app(TenantContext::class)->set($user->id);

    $service = app(ImportProxiesService::class);
    $first = $service->execute(makeIdempotencyCommand('1.2.3.4:1080'));
    $second = $service->execute(makeIdempotencyCommand('1.2.3.4:1080'));

    $entries = RawFeedEntry::query()->get();
    expect($entries)->toHaveCount(1)
        ->and($first->created)->toBe(1)
        ->and($second->created)->toBe(0);
});

it('different tenant with same batch_hash and line_number is allowed', function (): void {
    $userA = createIdempotencyUser();
    $userB = createIdempotencyUser();

    app(TenantContext::class)->set($userA->id);
    $service = app(ImportProxiesService::class);
    $service->execute(makeIdempotencyCommand('1.2.3.4:1080'));

    app(TenantContext::class)->set($userB->id);
    $result = $service->execute(makeIdempotencyCommand('1.2.3.4:1080'));
    expect($result->created)->toBe(1);

    app(TenantContext::class)->set($userA->id);
    expect(RawFeedEntry::query()->count())->toBe(1);

    app(TenantContext::class)->set($userB->id);
    expect(RawFeedEntry::query()->count())->toBe(1);
});
