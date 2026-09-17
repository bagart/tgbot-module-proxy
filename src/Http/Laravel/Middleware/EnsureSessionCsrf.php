<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Http\Laravel\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ensures the session carries a valid CSRF token for Mini App / web form
 * submissions (T53).
 */
final class EnsureSessionCsrf
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('GET')) {
            return $next($request);
        }

        $token = $request->input('_token') ?? $request->header('X-CSRF-Token');
        $sessionToken = $request->session()->token();

        if ($token === null || $token !== $sessionToken) {
            return response()->json(['error' => 'CSRF token mismatch'], 419);
        }

        return $next($request);
    }
}
