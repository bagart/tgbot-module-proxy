<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Tests\Unit;

use BAGArt\ProxyOperations\ProxyOperationsModule;
use BAGArt\ProxyOperations\Web\ProxyInventoryHandler;
use BAGArt\TelegramBot\Modules\AttributedComponentsScanner;
use BAGArt\TelegramBot\Modules\ModuleScopedRegistrar;
use BAGArt\TelegramBot\Modules\TgCommandRegistry;
use BAGArt\TelegramBot\Modules\TgModuleCapability;
use BAGArt\TelegramBot\Modules\TgWebApiRegistry;
use BAGArt\TelegramBot\Modules\TgWebPermissionRegistry;
use BAGArt\TelegramBot\Modules\TgWebResourceRegistry;
use BAGArt\TelegramBot\Modules\TgWebUiRegistry;
use BAGArt\TelegramBot\Modules\TypedModuleRegistrar;
use BAGArt\TelegramBot\Processing\TypeDTOProcessorRegistry;
use BAGArt\TelegramBotMenu\Manifest\ChatScope;
use BAGArt\TelegramBotMenu\Manifest\EffectiveRole;
use PHPUnit\Framework\TestCase;

/**
 * menu_integration.md M-6 slice 2: the wrapper registers the /proxy command
 * and the hub inventory read; the webApi declaration requires module
 * ownership scope (D38), so registration runs through ModuleScopedRegistrar.
 */
final class ProxyOperationsModuleTest extends TestCase
{
    public function test_descriptor_ships_disabled_fail_closed_with_command_and_ui_caps(): void
    {
        $descriptor = ProxyOperationsModule::descriptor();

        self::assertSame('proxy', $descriptor->id);
        self::assertFalse($descriptor->defaultEnabled);
        self::assertTrue($descriptor->failClosed);
        self::assertContains(TgModuleCapability::Command, $descriptor->capabilities);
        self::assertContains(TgModuleCapability::Ui, $descriptor->capabilities);
    }

    public function test_register_declares_proxy_command_and_inventory_route(): void
    {
        $commandRegistry = new TgCommandRegistry();
        $webApiRegistry = new TgWebApiRegistry();

        $registrar = new ModuleScopedRegistrar(
            inner: new TypedModuleRegistrar(
                processorRegistry: new TypeDTOProcessorRegistry(),
                commandRegistry: $commandRegistry,
                attributedScanner: new AttributedComponentsScanner(cache: null),
            ),
            moduleId: ProxyOperationsModule::ID,
            webUiRegistry: new TgWebUiRegistry(),
            webApiRegistry: $webApiRegistry,
            webResourceRegistry: new TgWebResourceRegistry(),
            webPermissionRegistry: new TgWebPermissionRegistry(),
        );

        ProxyOperationsModule::register($registrar);

        self::assertTrue($commandRegistry->has('proxy'));
        self::assertContains(
            ProxyInventoryHandler::class,
            array_column($webApiRegistry->all(), 'class'),
        );

        $routes = ProxyInventoryHandler::routes();
        self::assertSame('GET', $routes[0]->method);
        self::assertSame('inventory', $routes[0]->path);
        self::assertSame(EffectiveRole::Admin, $routes[0]->minRole);
        self::assertSame(ChatScope::Optional, $routes[0]->chatScope);
    }
}
