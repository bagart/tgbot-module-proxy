<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Auth;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Application session DTO (plan §11.29).
 */
final readonly class ApplicationSession
{
    public function __construct(
        public string $sessionId,
        public int $userId,
        public string $workspaceId,
        public string $csrfToken,
        public string $createdAt,
        public string $expiresAt,
    ) {
    }
}

/**
 * Magic-link token DTO (plan §11.29).
 */
final readonly class MagicLinkToken
{
    public function __construct(
        public string $token,
        public string $userId,
        public string $workspaceId,
        public string $createdAt,
        public string $expiresAt,
        public ?string $consumedAt = null,
    ) {
    }

    public function isExpired(): bool
    {
        return new \DateTimeImmutable() > new \DateTimeImmutable($this->expiresAt);
    }

    public function isConsumed(): bool
    {
        return $this->consumedAt !== null;
    }
}

/**
 * Magic-link service (plan §11.29, §10.12 п.12).
 */
final class MagicLinkService
{
    public function generate(string $userId, string $workspaceId): MagicLinkToken
    {
        $token = bin2hex(random_bytes(32));
        $now = new \DateTimeImmutable();

        DB::table('magic_link_tokens')->insert([
            'id' => (string) Str::uuid(),
            'token_hash' => hash('sha256', $token),
            'user_id' => $userId,
            'workspace_id' => $workspaceId,
            'expires_at' => $now->modify('+15 minutes'),
            'created_at' => $now,
        ]);

        return new MagicLinkToken(
            token: $token,
            userId: $userId,
            workspaceId: $workspaceId,
            createdAt: $now->toIso8601String(),
            expiresAt: $now->modify('+15 minutes')->toIso8601String(),
        );
    }

    public function consume(string $token): ?MagicLinkToken
    {
        $hash = hash('sha256', $token);

        $row = DB::table('magic_link_tokens')
            ->where('token_hash', $hash)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->first();

        if ($row === null) {
            return null;
        }

        DB::table('magic_link_tokens')
            ->where('id', $row->id)
            ->update(['consumed_at' => now()]);

        return new MagicLinkToken(
            token: $token,
            userId: $row->user_id,
            workspaceId: $row->workspace_id,
            createdAt: $row->created_at,
            expiresAt: $row->expires_at,
            consumedAt: now()->toIso8601String(),
        );
    }

    public function revoke(string $userId): void
    {
        DB::table('magic_link_tokens')
            ->where('user_id', $userId)
            ->update(['consumed_at' => now()]);
    }
}
