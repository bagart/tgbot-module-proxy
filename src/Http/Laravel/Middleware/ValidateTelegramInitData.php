<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Http\Laravel\Middleware;

use BAGArt\ProxyOperations\Auth\TelegramInitDataVerifier;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Validates Telegram Mini App initData (plan §10.12 п.56, T53).
 * If valid, resolves workspace and sets session context.
 */
final class ValidateTelegramInitData
{
    public function __construct(
        private TelegramInitDataVerifier $verifier,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $initData = $request->input('initData');

        if (! is_string($initData) || $initData === '') {
            return response()->json(['error' => 'Missing initData'], 401);
        }

        $botToken = $request->input('botToken', '');
        $result = $this->verifier->verify($initData, (string) $botToken);

        if (! $result->valid) {
            return response()->json(['error' => 'Invalid initData'], 401);
        }

        $request->attributes->set('telegramUser', $result->user);
        $request->attributes->set('telegramSession', $result->session);

        return $next($request);
    }
}
