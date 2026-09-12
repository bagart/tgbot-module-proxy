<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Web;

use BAGArt\TelegramBotMenu\Contracts\TgWebUiContract;
use BAGArt\TelegramBotMenu\Manifest\TgWebUiManifest;
use BAGArt\TelegramBotMenu\Manifest\UiAudience;
use BAGArt\TelegramBotMenu\Manifest\UiEntry;
use BAGArt\TelegramBotMenu\Manifest\UiKind;

/**
 * Proxy Operations menu manifest. Chunk-track entry pointing at the built,
 * content-hashed bundle. Admin-only tool surface.
 */
final readonly class ProxyUi implements TgWebUiContract
{
    public static function manifest(): TgWebUiManifest
    {
        return new TgWebUiManifest(
            moduleId: 'proxy',
            title: 't:title',
            icon: '🔗',
            kind: UiKind::Tool,
            minAudience: UiAudience::Admin,
            entry: UiEntry::chunk(ChunkAsset::url()),
            sortKey: 'proxy',
            description: 't:description',
        );
    }

    public static function translations(): array
    {
        return [
            'en' => ['title' => 'Proxy Ops', 'description' => 'Manage proxy inventory, pools, and health.'],
            'ru' => ['title' => 'Прокси', 'description' => 'Управление инвентарём, пулами и состоянием прокси.'],
            'fr' => ['title' => 'Proxy Ops', 'description' => "Gérer l'inventaire, les pools et la santé des proxys."],
            'es' => ['title' => 'Proxy Ops', 'description' => 'Administrar inventario, pools y salud de proxies.'],
            'zh' => ['title' => '代理管理', 'description' => '管理代理库存、池和健康状态。'],
        ];
    }
}
