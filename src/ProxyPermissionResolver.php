<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations;

use BAGArt\TelegramBotMenu\Contracts\TgPermissionResolverContract;
use BAGArt\TelegramBotMenu\Manifest\EffectiveRole;
use BAGArt\TelegramBotMenu\Support\TgUiContext;

/**
 * RBAC resolver for proxy module (§8.10/D58 proof-of-concept).
 *
 * proxy.manage — Owner only (multi-tenant SaaS surface, one workspace per user).
 * proxy.view   — Admin and above can view proxy inventory.
 */
final class ProxyPermissionResolver implements TgPermissionResolverContract
{
    public const string MANAGE = 'proxy.manage';

    public const string VIEW = 'proxy.view';

    public static function permissions(): array
    {
        return [self::MANAGE, self::VIEW];
    }

    public function resolve(array $permissionIds, TgUiContext $context): array
    {
        $verdicts = [];

        foreach ($permissionIds as $id) {
            $verdicts[$id] = match ($id) {
                self::MANAGE => $context->role->atLeast(EffectiveRole::Owner),
                self::VIEW => $context->role->atLeast(EffectiveRole::Admin),
                default => false,
            };
        }

        return $verdicts;
    }

    public function revision(): string
    {
        return '1';
    }
}
