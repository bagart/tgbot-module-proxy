<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Bot;

use BAGArt\ProxyOperations\Application\ExportInventoryCommand;
use BAGArt\ProxyOperations\Application\ExportInventoryHandler;
use BAGArt\ProxyOperations\Application\ImportProxiesCommand;
use BAGArt\ProxyOperations\Application\ImportProxiesHandler;
use BAGArt\ProxyOperations\Application\ImportSource;
use BAGArt\ProxyOperations\Application\StartAuditCommand;
use BAGArt\ProxyOperations\Application\StartAuditHandler;
use BAGArt\ProxyOperations\Application\WorkspaceSettingsHandler;
use BAGArt\ProxyOperations\Application\WorkspaceSettingsQuery;
use BAGArt\ProxyOperations\Models\ProxyAccess;
use BAGArt\ProxyOperations\Models\ProxyAuditJob;
use BAGArt\ProxyOperations\Models\ProxyEndpoint;

/**
 * /start — welcome + feature detection.
 */
final class StartCommandHandler implements BotCommandHandler
{
    public function handles(): string
    {
        return 'start';
    }

    public function handle(BotCommandContext $context): array
    {
        return [
            'text' => "Welcome to Proxy Operations!\n\n"
                ."Available commands:\n"
                ."/import — Import proxies\n"
                ."/list — List proxies\n"
                ."/check — Run audit\n"
                ."/stats — View statistics\n"
                ."/export — Export proxies\n"
                .'/settings — Workspace settings',
        ];
    }
}

/**
 * /help — command reference.
 */
final class HelpCommandHandler implements BotCommandHandler
{
    public function handles(): string
    {
        return 'help';
    }

    public function handle(BotCommandContext $context): array
    {
        return [
            'text' => "Proxy Operations Commands:\n\n"
                ."/import <data> — Import proxies (paste text)\n"
                ."/list — List all proxies\n"
                ."/check — Run audit on all proxies\n"
                ."/stats — View proxy statistics\n"
                ."/export <format> — Export (json/txt/csv)\n"
                .'/settings — View workspace settings',
        ];
    }
}

/**
 * /import — import proxies from pasted text.
 */
final class ImportCommandHandler implements BotCommandHandler
{
    public function __construct(
        private ImportProxiesHandler $handler,
    ) {}

    public function handles(): string
    {
        return 'import';
    }

    public function handle(BotCommandContext $context): array
    {
        if ($context->arguments === '') {
            return ['text' => 'Please provide proxy data to import. Format: host:port:user:REDENTIAL'];
        }

        $command = new ImportProxiesCommand(
            tenantId: $context->tenantId,
            source: ImportSource::Paste,
            payload: $context->arguments,
            sourceLabel: 'telegram_bot',
        );

        $result = $this->handler->handle($command);

        if (! $result->success) {
            return ['text' => "Import failed: {$result->errorKey}"];
        }

        $imported = $result->data['imported'] ?? 0;
        $skipped = $result->data['skipped'] ?? 0;

        return ['text' => "Import complete: {$imported} imported, {$skipped} skipped"];
    }
}

/**
 * /list — list proxies with pagination.
 */
final class ListCommandHandler implements BotCommandHandler
{
    public function handles(): string
    {
        return 'list';
    }

    public function handle(BotCommandContext $context): array
    {
        $endpoints = ProxyEndpoint::query()
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        if ($endpoints->isEmpty()) {
            return ['text' => 'No proxies found. Use /import to add some.'];
        }

        $lines = ["Proxies (showing {$endpoints->count()}):\n"];
        foreach ($endpoints as $ep) {
            $lines[] = "• {$ep->protocol}://{$ep->host}:{$ep->port}";
        }

        return ['text' => implode("\n", $lines)];
    }
}

/**
 * /check — trigger audit.
 */
final class CheckCommandHandler implements BotCommandHandler
{
    public function __construct(
        private StartAuditHandler $handler,
    ) {}

    public function handles(): string
    {
        return 'check';
    }

    public function handle(BotCommandContext $context): array
    {
        $command = new StartAuditCommand(
            tenantId: $context->tenantId,
            trigger: 'bot_command',
            requestedBy: $context->userId,
        );

        $result = $this->handler->handle($command);

        if (! $result->success) {
            return ['text' => "Audit failed to start: {$result->errorKey}"];
        }

        $jobId = $result->data['job_id'] ?? 'unknown';

        return ['text' => "Audit started (job: {$jobId}). Use /stats to check progress."];
    }
}

/**
 * /stats — proxy statistics.
 */
final class StatsCommandHandler implements BotCommandHandler
{
    public function handles(): string
    {
        return 'stats';
    }

    public function handle(BotCommandContext $context): array
    {
        $total = ProxyEndpoint::count();
        $accessCount = ProxyAccess::where('tenant_id', $context->tenantId)->count();
        $jobs = ProxyAuditJob::where('tenant_id', $context->tenantId)
            ->orderByDesc('created_at')
            ->limit(5)
            ->get();

        $lines = [
            'Proxy Statistics:',
            "• Endpoints: {$total}",
            "• Accesses: {$accessCount}",
            "• Recent audits: {$jobs->count()}",
        ];

        foreach ($jobs as $job) {
            $lines[] = "  - {$job->status->value} ({$job->created_at->diffForHumans()})";
        }

        return ['text' => implode("\n", $lines)];
    }
}

/**
 * /export — export proxies.
 */
final class ExportCommandHandler implements BotCommandHandler
{
    public function __construct(
        private ExportInventoryHandler $handler,
    ) {}

    public function handles(): string
    {
        return 'export';
    }

    public function handle(BotCommandContext $context): array
    {
        $format = $context->arguments !== '' ? $context->arguments : 'txt';

        $command = new ExportInventoryCommand(
            tenantId: $context->tenantId,
            format: $format,
            requestedBy: $context->userId,
        );

        $result = $this->handler->handle($command);

        if (! $result->success) {
            return ['text' => "Export failed: {$result->errorKey}"];
        }

        return [
            'text' => "Export complete: {$result->data['record_count']} records ({$format})",
            'file' => $result->data['content'] ?? '',
            'filename' => $result->data['filename'] ?? 'export.txt',
        ];
    }
}

/**
 * /settings — workspace settings.
 */
final class SettingsCommandHandler implements BotCommandHandler
{
    public function __construct(
        private WorkspaceSettingsHandler $handler,
    ) {}

    public function handles(): string
    {
        return 'settings';
    }

    public function handle(BotCommandContext $context): array
    {
        $query = new WorkspaceSettingsQuery(tenantId: $context->tenantId);
        $result = $this->handler->handle($query);

        $data = $result->data ?? [];

        return [
            'text' => "Workspace Settings:\n"
                ."• Max import batch: {$data['max_import_batch']}\n"
                ."• Max concurrent audits: {$data['max_concurrent_audits']}\n"
                ."• Max proxies: {$data['max_proxies']}",
        ];
    }
}

/**
 * Routes bot commands to handlers (plan §11.10).
 */
final class BotCommandRouter
{
    /** @var array<string, BotCommandHandler> */
    private array $handlers = [];

    public function __construct(
        iterable $handlers,
    ) {
        foreach ($handlers as $handler) {
            $this->handlers[$handler->handles()] = $handler;
        }
    }

    public function route(BotCommandContext $context): array
    {
        $handler = $this->handlers[$context->command] ?? null;

        if ($handler === null) {
            return ['text' => "Unknown command: /{$context->command}. Type /help for available commands."];
        }

        return $handler->handle($context);
    }
}
