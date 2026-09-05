<?php

declare(strict_types=1);

use BAGArt\ProxyOperations\Encryption\ConfigKekProvider;
use Illuminate\Config\Repository;
use RuntimeException;

function encryptionTestConfig(array $encryption, array $app = []): Repository
{
    return new Repository([
        'proxy-operations' => ['encryption' => $encryption],
        'app' => $app,
    ]);
}

it('derives a 32-byte KEK from the configured material', function (): void {
    $provider = new ConfigKekProvider(encryptionTestConfig([
        'kek' => 'unit-test-kek-material',
        'fallback_to_app_key' => false,
        'key_version' => 'k1',
    ]));

    expect($provider->keyFor('k1'))->toBe(hash('sha256', 'unit-test-kek-material', true))
        ->and(strlen($provider->keyFor('k1')))->toBe(32)
        ->and($provider->currentVersion())->toBe('k1');
});

it('falls back to the application key when no dedicated KEK is set', function (): void {
    $provider = new ConfigKekProvider(encryptionTestConfig([
        'kek' => null,
        'fallback_to_app_key' => true,
    ], ['key' => 'base64:app-key-fallback']));

    expect($provider->keyFor('k1'))->toBe(hash('sha256', 'base64:app-key-fallback', true));
});

it('throws at construction when neither KEK source is usable (prod-shaped env)', function (): void {
    new ConfigKekProvider(encryptionTestConfig([
        'kek' => null,
        'fallback_to_app_key' => false,
    ]));
})->throws(RuntimeException::class, 'No KEK material is configured');

it('resolves historical versions from the historical_keks map', function (): void {
    $provider = new ConfigKekProvider(encryptionTestConfig([
        'kek' => 'current-material',
        'fallback_to_app_key' => false,
        'key_version' => 'k2',
        'historical_keks' => ['k1' => 'old-material'],
    ]));

    expect($provider->keyFor('k1'))->toBe(hash('sha256', 'old-material', true))
        ->and($provider->keyFor('k2'))->toBe(hash('sha256', 'current-material', true))
        ->and($provider->currentVersion())->toBe('k2');
});

it('throws for a version with no configured material (negative case)', function (): void {
    $provider = new ConfigKekProvider(encryptionTestConfig([
        'kek' => 'current-material',
        'fallback_to_app_key' => false,
        'key_version' => 'k2',
        'historical_keks' => ['k1' => 'old-material'],
    ]));

    $provider->keyFor('k9');
})->throws(RuntimeException::class, 'No KEK material is configured for KEK version "k9"');
