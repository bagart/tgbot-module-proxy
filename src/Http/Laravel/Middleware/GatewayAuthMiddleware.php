<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Http\Laravel\Middleware;

use BAGArt\ProxyOperations\Models\GatewayToken;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gateway API authentication middleware (plan §§10.12 п.14, 11.10).
 * Validates Bearer tokens and enforces rate limits.
 */
class GatewayAuthMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if ($token === null || $token === '') {
            return response()->json(['error' => 'Missing Bearer token'], 401);
        }

        $hash = hash('sha256', $token);

        $gatewayToken = GatewayToken::query()
            ->where('token_hash', $hash)
            ->where('is_active', true)
            ->first();

        if ($gatewayToken === null) {
            return response()->json(['error' => 'Invalid token'], 401);
        }

        if (! $gatewayToken->isValid()) {
            return response()->json(['error' => 'Token expired or inactive'], 401);
        }

        $rateLimit = $gatewayToken->rate_limit ?? 60;
        $key = 'gateway:' . $gatewayToken->id;

        if (RateLimiter::tooManyAttempts($key, $rateLimit)) {
            $retryAfter = RateLimiter::availableIn($key);

            return response()->json([
                'error' => 'Rate limit exceeded',
                'retry_after' => $retryAfter,
            ], 429);
        }

        RateLimiter::hit($key, 60);

        $gatewayToken->update(['last_used_at' => now()]);

        $request->attributes->set('gateway_token', $gatewayToken);
        $request->attributes->set('tenant_id', $gatewayToken->tenant_id);

        return $next($request);
    }
}
