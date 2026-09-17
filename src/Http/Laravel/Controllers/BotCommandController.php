<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Http\Laravel\Controllers;

use BAGArt\ProxyOperations\Bot\BotCommandContext;
use BAGArt\ProxyOperations\Bot\BotCommandRouter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Bridge from Telegram webhook updates to BotCommandRouter (T52).
 * Receives parsed updates, builds BotCommandContext, and dispatches.
 */
final class BotCommandController extends Controller
{
    public function __construct(
        private BotCommandRouter $router,
    ) {}

    /**
     * POST /proxy-operations/bot/command
     * Expects a pre-parsed update payload with command, arguments, chat, user.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'command' => 'required|string',
            'arguments' => 'nullable|string',
            'tenant_id' => 'required|string',
            'chat_id' => 'required|string',
            'user_id' => 'required|string',
            'locale' => 'nullable|string',
        ]);

        $context = new BotCommandContext(
            tenantId: $request->input('tenant_id'),
            chatId: $request->input('chat_id'),
            userId: $request->input('user_id'),
            command: $request->input('command'),
            arguments: $request->input('arguments', ''),
            locale: $request->input('locale', 'en'),
        );

        $result = $this->router->route($context);

        return response()->json($result);
    }
}
