<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Http\Laravel\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates web admin panel access behind the workspace-level web_panel_enabled
 * flag (plan §10.12 п.12, T54).
 */
final class WebPanelEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        $enabled = config('proxy-operations.web_panel.enabled', false);

        if (! $enabled) {
            abort(403, 'Web panel is disabled for this workspace.');
        }

        return $next($request);
    }
}
