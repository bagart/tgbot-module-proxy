<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Http\Laravel\Controllers;

use BAGArt\ProxyOperations\Auth\MagicLinkService;
use BAGArt\ProxyOperations\Auth\WorkspaceResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Magic-link request and verify endpoints for the web admin panel (T54).
 */
final class MagicLinkController extends Controller
{
    public function __construct(
        private MagicLinkService $magicLinkService,
        private WorkspaceResolver $workspaceResolver,
    ) {}

    /**
     * POST /proxy-operations/auth/magic-link/request
     * Request a magic-link (sends bot message, returns token hash for tracking).
     */
    public function request(Request $request): JsonResponse
    {
        $request->validate([
            'telegram_user_id' => 'required|integer',
        ]);

        $userId = (string) $request->input('telegram_user_id');
        $workspaceId = $this->workspaceResolver->resolve((int) $userId);

        $token = $this->magicLinkService->generate($userId, $workspaceId);

        return response()->json([
            'status' => 'pending',
            'token_hash' => hash('sha256', $token->token),
            'expires_at' => $token->expiresAt,
        ]);
    }

    /**
     * GET /proxy-operations/auth/magic-link/verify
     * Consume a magic-link token and establish a session.
     */
    public function verify(Request $request): JsonResponse
    {
        $request->validate([
            'token' => 'required|string',
        ]);

        $token = $this->magicLinkService->consume($request->input('token'));

        if ($token === null) {
            return response()->json(['error' => 'Invalid or expired token'], 401);
        }

        $request->session()->put('proxy_user_id', $token->userId);
        $request->session()->put('proxy_workspace_id', $token->workspaceId);

        return response()->json([
            'status' => 'authenticated',
            'workspace_id' => $token->workspaceId,
        ]);
    }
}
