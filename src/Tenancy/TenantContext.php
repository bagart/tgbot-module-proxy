<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Tenancy;

/**
 * Holds the authenticated workspace for the current execution scope
 * (request / console command / queued job — registered as a scoped singleton,
 * plan §11.21: tenant = platform user, 1 user = 1 workspace).
 *
 * Set/forget semantics with explicit scope boundaries: application code sets
 * the tenant right after authentication resolution and forgets it when leaving
 * the boundary. Access without a set tenant fails loudly (fail closed).
 */
final class TenantContext
{
    private ?int $userId = null;

    public function set(int $userId): void
    {
        $this->userId = $userId;
    }

    /**
     * @throws TenantNotResolvedException When no tenant is set in this scope.
     */
    public function id(): int
    {
        return $this->userId ?? throw new TenantNotResolvedException(
            'Tenant is not resolved in the current scope.',
        );
    }

    public function tryId(): ?int
    {
        return $this->userId;
    }

    public function forget(): void
    {
        $this->userId = null;
    }
}
