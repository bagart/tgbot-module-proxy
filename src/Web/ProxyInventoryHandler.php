<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Web;

use BAGArt\ProxyOperations\Bot\ProxyInventorySummary;
use BAGArt\ProxyOperations\Tenancy\TenantContext;
use BAGArt\TelegramBotMenu\Contracts\TgWebApiHandlerContract;
use BAGArt\TelegramBotMenu\Manifest\ChatScope;
use BAGArt\TelegramBotMenu\Manifest\EffectiveRole;
use BAGArt\TelegramBotMenu\Support\TgWebApiRoute;
use BAGArt\TelegramBotMenu\Support\TgWebRequest;
use BAGArt\TelegramBotMenu\Support\TgWebResponse;
use Throwable;

/**
 * webApi inventory read for the menu hub (menu_integration.md M-6 slice 2).
 * Tenant = the hub user (1 user = 1 workspace): the TenantContext is set
 * from TgUiContext->user for the duration of the call and always forgotten.
 * Masked counts only — no hosts, no credentials (module hard rule).
 */
final readonly class ProxyInventoryHandler implements TgWebApiHandlerContract
{
    /** @return list<TgWebApiRoute> */
    public static function routes(): array
    {
        return [
            new TgWebApiRoute('GET', 'inventory', EffectiveRole::Admin, chatScope: ChatScope::Optional),
        ];
    }

    public function handle(TgWebRequest $request, array $path): TgWebResponse
    {
        if ($path !== ['inventory']) {
            return TgWebResponse::error('not_found', 'Unknown proxy route.', 404, $request->requestId);
        }

        /** @var TenantContext $tenant */
        $tenant = app(TenantContext::class);

        try {
            $tenant->set($request->context->user->id);
            $summary = ProxyInventorySummary::take();
        } catch (Throwable) {
            return TgWebResponse::error('internal', 'Inventory is not available right now.', 500, $request->requestId);
        } finally {
            $tenant->forget();
        }

        return TgWebResponse::ok($summary);
    }
}
