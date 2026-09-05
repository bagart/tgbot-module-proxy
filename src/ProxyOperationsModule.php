<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations;

use BAGArt\ProxyOperations\Web\ProxyInventoryHandler;
use BAGArt\TelegramBot\Modules\TgModuleCapability;
use BAGArt\TelegramBot\Modules\TgModuleContract;
use BAGArt\TelegramBot\Modules\TgModuleDescriptor;
use BAGArt\TelegramBot\Modules\TgModuleRegistrar;

/**
 * Platform module wrapper for Proxy Operations (menu_integration.md M-6).
 *
 * Ships DISABLED (opt-in, multi-tenant SaaS surface — plan.md «Multi-tenant
 * everywhere»). The Application API (parser/checker/gateway services, wired
 * by the Laravel provider) is consumed by its own web admin; the tenant model
 * maps the menu-hub TgUiContext user to the workspace (1 user = 1 workspace,
 * Owner only). The next slice — §8.3 settings surface and the ProxyUi Mini
 * App chunk — lands per plan.md §10.12 item 22.
 */
final class ProxyOperationsModule implements TgModuleContract
{
    public const string ID = 'proxy';

    public const string VERSION = '0.1.0';

    public static function descriptor(): TgModuleDescriptor
    {
        return new TgModuleDescriptor(
            id: self::ID,
            name: 'Proxy Operations',
            version: self::VERSION,
            capabilities: [
                TgModuleCapability::Command,
                TgModuleCapability::Ui,
            ],
            // Opt-in even at platform level: the module is multi-tenant SaaS
            // surface, not a chat utility (plan.md «Multi-tenant everywhere»).
            defaultEnabled: false,
            failClosed: true,
        );
    }

    public static function register(TgModuleRegistrar $registrar): void
    {
        // M-6 slice 2 (menu_integration.md): /proxy inventory card (private
        // chats only, tenant = bot owner) and the hub inventory read. Settings
        // surface + ProxyUi chunk follow per plan.md §10.12 item 22.
        $registrar->registerAttributed(self::class);
        $registrar->webApi(ProxyInventoryHandler::class);
    }
}
