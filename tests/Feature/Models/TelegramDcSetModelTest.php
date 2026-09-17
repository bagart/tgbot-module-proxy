<?php

declare(strict_types=1);

use BAGArt\ProxyOperations\Database\Seeders\TelegramDcSetSeeder;
use BAGArt\ProxyOperations\Domain\Snapshot\TelegramDcSet;
use BAGArt\ProxyOperations\Models\TelegramDcSetModel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

it('creates five DC entries via seeder', function (): void {
    $seeder = new TelegramDcSetSeeder;
    $seeder->run();

    expect(TelegramDcSetModel::count())->toBe(5);
});

it('is idempotent on re-run', function (): void {
    $seeder = new TelegramDcSetSeeder;
    $seeder->run();
    $seeder->run();

    expect(TelegramDcSetModel::where('version', 1)->count())->toBe(5);
});

it('hydrates TelegramDcSet DTO from DB rows', function (): void {
    (new TelegramDcSetSeeder)->run();

    $dto = TelegramDcSetModel::latestSet();

    expect($dto)->not->toBeNull()
        ->and($dto->version)->toBe(1)
        ->and($dto->dcs)->toHaveCount(5)
        ->and($dto->dcs[0]->dcId)->toBeInt();
});

it('round-trips through JSON serialization', function (): void {
    (new TelegramDcSetSeeder)->run();

    $dto = TelegramDcSetModel::latestSet();
    $json = $dto->jsonSerialize();
    $restored = TelegramDcSet::fromJson($json);

    expect($restored)->toEqual($dto);
});

it('has unique constraint on version+dc_id', function (): void {
    $seeder = new TelegramDcSetSeeder;
    $seeder->run();

    // Attempting to insert duplicate should throw
    TelegramDcSetModel::create([
        'id' => (string) Str::uuid(),
        'version' => 1,
        'dc_id' => 1,
        'addresses' => ['1.2.3.4'],
        'ports' => [443],
        'enabled' => true,
        'created_at' => now(),
    ]);
})->throws(QueryException::class);
