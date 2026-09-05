<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('boots the host application with the module provider registered', function (): void {
    expect(app())->toBeInstanceOf(Application::class)
        ->and(config('proxy-operations.encryption.algorithm'))->toBe('aes-256-gcm')
        ->and(config('proxy-operations.encryption.fallback_to_app_key'))->toBeTrue()
        ->and(config('proxy-operations.retention.enabled'))->toBeFalse()
        ->and(config('proxy-operations.tenancy'))->toBe([])
        ->and(config('proxy-operations.probe_defaults'))->toBe([]);
});

it('runs the host migrations against in-memory sqlite', function (): void {
    expect(DB::connection()->getDriverName())->toBe('sqlite')
        ->and(Schema::hasTable('users'))->toBeTrue();
});
