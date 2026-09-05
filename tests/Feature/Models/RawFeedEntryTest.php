<?php

declare(strict_types=1);

use App\Models\User;
use BAGArt\ProxyOperations\Models\ImmutableRecordException;
use BAGArt\ProxyOperations\Models\ProxyEndpoint;
use BAGArt\ProxyOperations\Models\ProxySource;
use BAGArt\ProxyOperations\Models\RawFeedEntry;
use BAGArt\ProxyOperations\Models\RawFeedEntryStatus;
use BAGArt\ProxyOperations\Tenancy\TenantContext;
use BAGArt\ProxyOperations\Tenancy\TenantNotResolvedException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function createRawFeedTenantUser(): User
{
    return User::factory()->create();
}

it('creates the raw_feed_entries table with expected columns and indexes', function (): void {
    expect(Schema::hasTable('raw_feed_entries'))->toBeTrue()
        ->and(Schema::hasColumns('raw_feed_entries', [
            'id', 'tenant_id', 'source_id', 'import_batch_id', 'batch_hash',
            'raw_line', 'line_number', 'status', 'parsed_entry_json',
            'parse_error_json', 'endpoint_id', 'created_at', 'updated_at',
        ]))->toBeTrue();

    $indexes = collect(DB::select("PRAGMA index_list('raw_feed_entries')"));

    $uniqueIndex = $indexes->firstWhere(fn (object $index) => (bool) $index->unique
        && str_contains((string) $index->name, 'batch_hash'));
    $statusIndex = $indexes->firstWhere(fn (object $index) => ! ((bool) $index->unique)
        && str_contains((string) $index->name, 'status'));
    $batchIndex = $indexes->firstWhere(fn (object $index) => ! ((bool) $index->unique)
        && str_contains((string) $index->name, 'import_batch_id'));

    expect($uniqueIndex)->not->toBeNull()
        ->and($statusIndex)->not->toBeNull()
        ->and($batchIndex)->not->toBeNull();

    $uniqueColumns = collect(DB::select("PRAGMA index_info('{$uniqueIndex->name}')"))
        ->pluck('name')->all();

    expect($uniqueColumns)->toBe(['tenant_id', 'batch_hash', 'line_number']);
});

it('creates a row within tenant context with tenant_id force-filled from the context', function (): void {
    $user = createRawFeedTenantUser();
    app(TenantContext::class)->set($user->id);

    $entry = RawFeedEntry::factory()->create();

    expect($entry->tenant_id)->toBe($user->id)
        ->and($entry->status)->toBe(RawFeedEntryStatus::Pending)
        ->and($entry->raw_line)->toBe('1.2.3.4:1080')
        ->and($entry->line_number)->toBe(1)
        ->and(RawFeedEntry::query()->whereKey($entry->id)->exists())->toBeTrue();
});

it('casts status to RawFeedEntryStatus enum', function (): void {
    $user = createRawFeedTenantUser();
    app(TenantContext::class)->set($user->id);

    $entry = RawFeedEntry::factory()->create(['status' => 'parsed']);

    expect($entry->status)->toBe(RawFeedEntryStatus::Parsed);
});

it('casts parsed_entry_json and parse_error_json to arrays', function (): void {
    $user = createRawFeedTenantUser();
    app(TenantContext::class)->set($user->id);

    $entry = RawFeedEntry::factory()->parsed()->create();

    expect($entry->parsed_entry_json)->toBeArray()
        ->and($entry->parse_error_json)->toBeNull();
});

it('rejects a duplicate (tenant_id, batch_hash, line_number) at DB level', function (): void {
    $user = createRawFeedTenantUser();
    app(TenantContext::class)->set($user->id);

    $hash = hash('sha256', '1.2.3.4:1080');
    RawFeedEntry::factory()->create([
        'batch_hash' => $hash,
        'line_number' => 1,
    ]);
    RawFeedEntry::factory()->create([
        'batch_hash' => $hash,
        'line_number' => 1,
    ]);
})->throws(UniqueConstraintViolationException::class);

it('allows the same (batch_hash, line_number) in a second tenant (cross-tenant)', function (): void {
    $context = app(TenantContext::class);

    $hash = hash('sha256', '1.2.3.4:1080');
    $context->set(createRawFeedTenantUser()->id);
    $mine = RawFeedEntry::factory()->create([
        'batch_hash' => $hash,
        'line_number' => 1,
    ]);

    $context->set(createRawFeedTenantUser()->id);
    $theirs = RawFeedEntry::factory()->create([
        'batch_hash' => $hash,
        'line_number' => 1,
    ]);

    expect(RawFeedEntry::query()->count())->toBe(1)
        ->and($mine->batch_hash)->toBe($theirs->batch_hash)
        ->and($mine->line_number)->toBe($theirs->line_number);
});

it('raises on update attempts after persist', function (): void {
    $user = createRawFeedTenantUser();
    app(TenantContext::class)->set($user->id);

    $entry = RawFeedEntry::factory()->create();

    $entry->update(['raw_line' => '5.6.7.8:8080']);
})->throws(ImmutableRecordException::class);

it('raises on delete attempts', function (): void {
    $user = createRawFeedTenantUser();
    app(TenantContext::class)->set($user->id);

    $entry = RawFeedEntry::factory()->create();
    $id = $entry->id;

    try {
        $entry->delete();
        $this->fail('Expected ImmutableRecordException.');
    } catch (ImmutableRecordException) {
        // expected
    }

    expect(fn () => $entry->refresh())->not->toThrow(Exception::class)
        ->and(DB::table('raw_feed_entries')->where('id', $id)->exists())->toBeTrue();
});

it('throws on queries without a resolved tenant instead of falling back to unscoped', function (): void {
    RawFeedEntry::query()->get();
})->throws(TenantNotResolvedException::class);

it('throws on creates without a resolved tenant', function (): void {
    RawFeedEntry::factory()->create();
})->throws(TenantNotResolvedException::class);

it('resolves source() relationship', function (): void {
    $user = createRawFeedTenantUser();
    app(TenantContext::class)->set($user->id);

    $entry = RawFeedEntry::factory()->create([
        'source_id' => ProxySource::factory(),
    ]);

    expect($entry->source)->not->toBeNull()
        ->and($entry->source)->toBeInstanceOf(ProxySource::class);
});

it('resolves endpoint() relationship', function (): void {
    $user = createRawFeedTenantUser();
    app(TenantContext::class)->set($user->id);

    $entry = RawFeedEntry::factory()->withEndpoint()->create();

    expect($entry->endpoint)->not->toBeNull()
        ->and($entry->endpoint)->toBeInstanceOf(ProxyEndpoint::class);
});
