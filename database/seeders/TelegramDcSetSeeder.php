<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Database\Seeders;

use BAGArt\ProxyOperations\Models\TelegramDcSetModel;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Seeds production Telegram DC addresses (DC1–DC5) at version 1.
 * Idempotent: skips if version 1 already exists.
 *
 * @see https://core.telegram.org/mtproto/dc
 */
class TelegramDcSetSeeder extends Seeder
{
    private const DC_ADDRESSES = [
        1 => [
            'addresses' => ['149.154.175.50'],
            'ports' => [443, 80],
        ],
        2 => [
            'addresses' => ['149.154.167.51'],
            'ports' => [443, 80],
        ],
        3 => [
            'addresses' => ['149.154.175.100'],
            'ports' => [443, 80],
        ],
        4 => [
            'addresses' => ['149.154.167.91'],
            'ports' => [443, 80],
        ],
        5 => [
            'addresses' => ['149.154.175.72'],
            'ports' => [443, 80],
        ],
    ];

    public function run(): void
    {
        $version = 1;

        if (TelegramDcSetModel::where('version', $version)->exists()) {
            return;
        }

        $now = now();

        foreach (self::DC_ADDRESSES as $dcId => $dc) {
            TelegramDcSetModel::create([
                'id' => (string) Str::uuid(),
                'version' => $version,
                'dc_id' => $dcId,
                'addresses' => $dc['addresses'],
                'ports' => $dc['ports'],
                'enabled' => true,
                'description' => "Telegram DC{$dcId}",
                'created_at' => $now,
            ]);
        }
    }
}
