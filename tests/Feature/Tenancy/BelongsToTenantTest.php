<?php

declare(strict_types=1);

use BAGArt\ProxyOperations\Models\Concerns\BelongsToTenant;
use BAGArt\ProxyOperations\Tenancy\TenantContext;
use BAGArt\ProxyOperations\Tenancy\TenantNotResolvedException;
use BAGArt\ProxyOperations\Tests\Fixtures\TenantedThing;
use BAGArt\ProxyOperations\Tests\Fixtures\UntenantedThing;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    Schema::create('tenancy_test_things', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('tenant_id');
        $table->string('name');
        $table->timestamps();

        $table->index('tenant_id');
    });

    Schema::create('untenancy_test_things', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });
});

it('resolves TenantContext as a scoped singleton shared within one scope', function (): void {
    $context = App::make(TenantContext::class);

    $context->set(11);

    expect(App::make(TenantContext::class))->toBe($context)
        ->and(App::make(TenantContext::class)->id())->toBe(11);
});

it('fills tenant_id from the context, overwriting any caller-supplied value (INV-006)', function (): void {
    app(TenantContext::class)->set(1);

    // forceFill bypasses the guard on purpose: tenant_id must never be in
    // $fillable, yet even a forced client-provided value loses (INV-006).
    $thing = new TenantedThing;
    $thing->forceFill(['name' => 'forced-tenant']);
    $thing->tenant_id = 999_999_999;
    $thing->save();

    $row = TenantedThing::query()->findOrFail($thing->getKey());

    expect($row->tenant_id)->toBe(1);
});

it('returns only current-tenant rows when two tenants hold rows (negative cross-tenant case)', function (): void {
    $context = app(TenantContext::class);

    $context->set(1);
    TenantedThing::query()->create(['name' => 'mine-1']);
    TenantedThing::query()->create(['name' => 'mine-2']);

    $context->set(2);
    TenantedThing::query()->create(['name' => 'theirs']);

    $context->set(1);
    expect(TenantedThing::query()->pluck('name')->all())->toBe(['mine-1', 'mine-2']);

    $context->set(2);
    expect(TenantedThing::query()->pluck('name')->all())->toBe(['theirs'])
        ->and(TenantedThing::query()->where('name', 'mine-1')->exists())->toBeFalse();
});

it('throws on queries without a resolved tenant instead of falling back to unscoped', function (): void {
    TenantedThing::query()->get();
})->throws(TenantNotResolvedException::class);

it('throws on creates without a resolved tenant', function (): void {
    TenantedThing::query()->create(['name' => 'orphan']);
})->throws(TenantNotResolvedException::class);

it('forgets the tenant and fails closed again after an explicit scope boundary', function (): void {
    app(TenantContext::class)->set(5);
    TenantedThing::query()->create(['name' => 'scoped']);

    app(TenantContext::class)->forget();

    expect(app(TenantContext::class)->tryId())->toBeNull()
        ->and(fn () => TenantedThing::query()->count())->toThrow(TenantNotResolvedException::class);
});

it('leaves models without the trait completely unaffected by tenancy', function (): void {
    UntenantedThing::query()->create(['name' => 'plain-a']);
    UntenantedThing::query()->create(['name' => 'plain-b']);

    expect(UntenantedThing::query()->count())->toBe(2)
        ->and(in_array(BelongsToTenant::class, class_uses_recursive(UntenantedThing::class), true))->toBeFalse()
        ->and(fn () => TenantedThing::query()->count())->toThrow(TenantNotResolvedException::class);
});
