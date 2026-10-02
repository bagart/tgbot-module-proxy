<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Bot;

use BAGArt\ProxyOperations\Models\ProxyAccess;
use BAGArt\ProxyOperations\Models\ProxyEndpoint;
use BAGArt\ProxyOperations\Tenancy\TenantContext;

/**
 * /get — retrieve a single proxy by access ID or label.
 * Returns host:port (no credentials) unless in private chat.
 */
final class GetCommandHandler implements BotCommandHandler
{
    public function __construct(
        private TenantContext $tenantContext,
    ) {
    }

    public function handles(): string
    {
        return 'get';
    }

    public function handle(BotCommandContext $context): array
    {
        if ($context->arguments === '') {
            return ['text' => 'Usage: /get <access_id or label>'];
        }

        $tenantId = (int) $context->tenantId;

        try {
            $this->tenantContext->set($tenantId);

            $access = ProxyAccess::query()
                ->where('id', $context->arguments)
                ->orWhere('label', $context->arguments)
                ->first();

            if ($access === null) {
                return ['text' => "Proxy '{$context->arguments}' not found."];
            }

            $endpoint = ProxyEndpoint::find($access->endpoint_id);

            if ($endpoint === null) {
                return ['text' => 'Proxy endpoint data is unavailable.'];
            }

            $text = sprintf(
                '%s://%s:%d (state: %s, health: %s)',
                $endpoint->protocol->value,
                $endpoint->host,
                $endpoint->port,
                $access->state->value,
                $access->health_score !== null ? round($access->health_score, 2).'%' : 'N/A',
            );

            return ['text' => $text];
        } catch (\Throwable) {
            return ['text' => 'Could not retrieve proxy info.'];
        } finally {
            $this->tenantContext->forget();
        }
    }
}
