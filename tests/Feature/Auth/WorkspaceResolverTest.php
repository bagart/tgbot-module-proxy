<?php

declare(strict_types=1);

use BAGArt\ProxyOperations\Auth\WorkspaceResolver;
use Illuminate\Support\Facades\DB;

/**
 * The proxy raw-insert gap (docs/questions/telegram-first-user-provisioning.md):
 * WorkspaceResolver used to force a uuid id and skip NOT NULL columns.
 * It now delegates to the platform identity service.
 */
describe('WorkspaceResolver', function () {
    it('provisions a telegram-first user without email or password', function () {
        $resolver = app(WorkspaceResolver::class);

        $userId = $resolver->resolve(555000111);

        $row = DB::table('users')->where('id', $userId)->first();
        expect($row)->not->toBeNull()
            ->and($row->telegram_id)->toBe(555000111)
            ->and($row->name)->toBe('tg_555000111')
            ->and($row->email)->toBeNull()
            ->and($row->password)->toBeNull();
    });

    it('is idempotent for the same telegram id', function () {
        $resolver = app(WorkspaceResolver::class);

        $first = $resolver->resolve(555000222);
        $second = $resolver->resolve(555000222);

        expect($second)->toBe($first)
            ->and(DB::table('users')->where('telegram_id', 555000222)->count())->toBe(1);
    });

    it('resolves an already existing web-created user instead of duplicating', function () {
        $existingId = DB::table('users')->insertGetId([
            'name' => 'Web User',
            'email' => 'web@example.com',
            'password' => 'irrelevant-hash',
            'telegram_id' => 555000333,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $resolver = app(WorkspaceResolver::class);

        expect($resolver->resolve(555000333))->toBe((string) $existingId)
            ->and(DB::table('users')->where('telegram_id', 555000333)->count())->toBe(1);
    });
});
