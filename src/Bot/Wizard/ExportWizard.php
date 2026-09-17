<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Bot\Wizard;

use BAGArt\ProxyOperations\Application\ExportInventoryCommand;
use BAGArt\ProxyOperations\Application\ExportInventoryHandler;

/**
 * Export wizard (plan §§10.12 п.11, 11.10):
 * Step 1: Choose format (TXT / CSV / JSON / proxychains / curl / clash / Telegram URI)
 * Step 2: Choose scope (all / filtered)
 * Step 3: Confirm
 * Step 4: Execute + deliver
 */
final class ExportWizard extends BaseWizard
{
    public function start(int $userId): array
    {
        $session = WizardSession::create($userId, WizardType::Export);

        return $this->saveAndReply(
            $session,
            'Export proxies — choose format:',
            [
                [['text' => '📄 TXT', 'callback_data' => 'wizard:export:txt'], ['text' => '📊 CSV', 'callback_data' => 'wizard:export:csv']],
                [['text' => '🔧 JSON', 'callback_data' => 'wizard:export:json'], ['text' => '🔗 proxychains', 'callback_data' => 'wizard:export:proxychains']],
                [['text' => '🌐 curl', 'callback_data' => 'wizard:export:curl'], ['text' => '⚔️ clash', 'callback_data' => 'wizard:export:clash']],
                [['text' => '📱 Telegram URI', 'callback_data' => 'wizard:export:uri']],
                [['text' => '❌ Cancel', 'callback_data' => 'wizard:cancel']],
            ],
        );
    }

    public function handle(int $userId, string $input): array
    {
        $session = $this->session($userId);

        if ($session === null) {
            return ['text' => 'No active wizard. Use /export to start.', 'keyboard' => []];
        }

        return match ($session->step) {
            'init' => $this->handleFormat($session, $input),
            'scope' => $this->handleScope($session, $input),
            'confirm' => $this->handleConfirm($session, $input),
            default => ['text' => 'Unknown step.', 'keyboard' => []],
        };
    }

    private function handleFormat(WizardSession $session, string $input): array
    {
        $format = 'txt';
        foreach (['txt', 'csv', 'json', 'proxychains', 'curl', 'clash', 'uri'] as $f) {
            if (str_contains($input, $f)) {
                $format = $f;
                break;
            }
        }

        $newSession = $session->withStep('scope', ['format' => $format]);

        return $this->saveAndReply(
            $newSession,
            "Format: {$format}. Choose scope:",
            [
                [['text' => 'All proxies', 'callback_data' => 'wizard:export:all']],
                [['text' => '❌ Cancel', 'callback_data' => 'wizard:cancel']],
            ],
        );
    }

    private function handleScope(WizardSession $session, string $input): array
    {
        $newSession = $session->withStep('confirm', ['scope' => 'all']);

        return $this->saveAndReply(
            $newSession,
            'Export all proxies? Confirm:',
            [
                [['text' => '✅ Export', 'callback_data' => 'wizard:export:yes']],
                [['text' => '❌ Cancel', 'callback_data' => 'wizard:cancel']],
            ],
        );
    }

    private function handleConfirm(WizardSession $session, string $input): array
    {
        if (str_contains($input, 'yes') || str_contains($input, 'Export')) {
            return $this->executeExport($session);
        }

        $this->sessions->delete($session->userId);

        return ['text' => 'Export cancelled.', 'keyboard' => []];
    }

    private function executeExport(WizardSession $session): array
    {
        $format = $session->payload['format'] ?? 'txt';

        try {
            $handler = app(ExportInventoryHandler::class);
            $result = $handler->handle(new ExportInventoryCommand(
                tenantId: (string) $session->userId,
                format: $format,
            ));

            $this->sessions->delete($session->userId);

            return ['text' => "Exported " . count($result->entries) . " proxies as {$format}.", 'keyboard' => []];
        } catch (\Throwable $e) {
            $this->sessions->delete($session->userId);

            return ['text' => "Export failed: {$e->getMessage()}", 'keyboard' => []];
        }
    }
}
