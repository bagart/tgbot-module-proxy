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
 * Ships DISABLED (opt-in, multi-tenant SaaS surface — sdd.md §14).
 * The Application API (parser/checker/gateway services, wired
 * by the Laravel provider) is consumed by its own web admin; the tenant model
 * maps the menu-hub TgUiContext user to the workspace (1 user = 1 workspace,
 * Owner only).
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
            // surface, not a chat utility (sdd.md §14).
            defaultEnabled: false,
            failClosed: true,
        );
    }

    public static function register(TgModuleRegistrar $registrar): void
    {
        // M-6 slice 2 (menu_integration.md): /proxy inventory card (private
        // chats only, tenant = bot owner) and the hub inventory read.
        $registrar->registerAttributed(self::class);
        $registrar->webApi(ProxyInventoryHandler::class);
    }
}
