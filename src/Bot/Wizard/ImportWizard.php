<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Bot\Wizard;

use BAGArt\ProxyOperations\Application\ImportProxiesCommand;
use BAGArt\ProxyOperations\Application\ImportSource;

/**
 * Import wizard (plan §§10.12 п.11, 11.10):
 * Step 1: Choose input method (paste / URL / file)
 * Step 2: Input data
 * Step 3: Preview parsed entries
 * Step 4: Confirm import
 * Step 5: Execute + progress
 * Step 6: Result summary
 */
final class ImportWizard extends BaseWizard
{
    public function start(int $userId): array
    {
        $session = WizardSession::create($userId, WizardType::Import);

        return $this->saveAndReply(
            $session,
            'Import proxies — choose input method:',
            [
                [['text' => '📋 Paste text', 'callback_data' => 'wizard:import:paste']],
                [['text' => '🔗 URL', 'callback_data' => 'wizard:import:url']],
                [['text' => '❌ Cancel', 'callback_data' => 'wizard:cancel']],
            ],
        );
    }

    public function handle(int $userId, string $input): array
    {
        $session = $this->session($userId);

        if ($session === null) {
            return ['text' => 'No active wizard. Use /import to start.', 'keyboard' => []];
        }

        return match ($session->step) {
            'init' => $this->handleMethod($session, $input),
            'input' => $this->handleInput($session, $input),
            'confirm' => $this->handleConfirm($session, $input),
            default => ['text' => 'Unknown step.', 'keyboard' => []],
        };
    }

    private function handleMethod(WizardSession $session, string $input): array
    {
        $method = match (true) {
            str_contains($input, 'paste') => 'paste',
            str_contains($input, 'url') => 'url',
            default => null,
        };

        if ($method === null) {
            return ['text' => 'Choose paste or URL.', 'keyboard' => []];
        }

        $newSession = $session->withStep('input', ['method' => $method]);
        $prompt = $method === 'paste'
            ? 'Paste your proxy list (one per line):'
            : 'Enter the feed URL:';

        return $this->saveAndReply($newSession, $prompt);
    }

    private function handleInput(WizardSession $session, string $input): array
    {
        $lines = array_filter(explode("\n", trim($input)));
        $count = count($lines);

        $newSession = $session->withStep('confirm', ['raw' => $input, 'count' => $count]);

        return $this->saveAndReply(
            $newSession,
            "Found {$count} proxy entries. Import?",
            [
                [['text' => '✅ Import', 'callback_data' => 'wizard:import:yes']],
                [['text' => '❌ Cancel', 'callback_data' => 'wizard:cancel']],
            ],
        );
    }

    private function handleConfirm(WizardSession $session, string $input): array
    {
        if (str_contains($input, 'yes') || str_contains($input, 'Import')) {
            return $this->executeImport($session);
        }

        $this->sessions->delete($session->userId);

        return ['text' => 'Import cancelled.', 'keyboard' => []];
    }

    private function executeImport(WizardSession $session): array
    {
        $raw = $session->payload['raw'] ?? '';

        if ($raw === '') {
            $this->sessions->delete($session->userId);

            return ['text' => 'No data to import.', 'keyboard' => []];
        }

        $lines = array_filter(explode("\n", trim($raw)));
        $imported = 0;
        $errors = 0;

        foreach ($lines as $line) {
            try {
                $command = new ImportProxiesCommand(
                    rawLines: [(string) $line],
                    tenantId: (string) $session->userId,
                    source: ImportSource::Bot,
                );

                $result = app(\BAGArt\ProxyOperations\Application\ImportProxiesHandler::class)->handle($command);
                $imported += $result->imported;
            } catch (\Throwable) {
                $errors++;
            }
        }

        $this->sessions->delete($session->userId);

        return ['text' => "Import complete: {$imported} imported, {$errors} errors.", 'keyboard' => []];
    }
}
