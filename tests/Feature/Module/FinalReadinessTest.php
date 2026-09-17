<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

it('artisan commands are registered', function (): void {
    $commands = Artisan::all();

    $expected = [
        'proxy:import',
        'proxy:list',
        'proxy:check',
        'proxy:export',
        'proxy:pools:list',
        'proxy:pools:create',
        'proxy:settings',
        'proxy:status',
    ];

    foreach ($expected as $name) {
        expect(array_key_exists($name, $commands))->toBeTrue("Command {$name} not registered");
    }
});

it('proxy-operations config is published and readable', function (): void {
    expect(config('proxy-operations'))->toBeArray()
        ->and(array_key_exists('encryption', config('proxy-operations')))->toBeTrue()
        ->and(array_key_exists('audit', config('proxy-operations')))->toBeTrue();
});

it('service provider boots without exceptions', function (): void {
    $provider = app()->getProvider(\BAGArt\ProxyOperations\ProxyOperationsServiceProvider::class);
    expect($provider)->not->toBeNull();
});
