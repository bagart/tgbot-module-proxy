<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Bot\Wizard;

/**
 * Routes wizard callback data and text inputs to the correct wizard handler.
 * Handles /import, /export, /list, /check, /settings bot commands.
 */
final class WizardRouter
{
    /** @var array<string, BaseWizard> */
    private array $wizards = [];

    public function __construct(
        private readonly WizardSessionStore $sessions,
    ) {
        $this->wizards = [
            'import' => new ImportWizard($sessions),
            'export' => new ExportWizard($sessions),
        ];
    }

    /**
     * Route a wizard command (e.g., /import) or callback data (e.g., wizard:import:paste).
     */
    public function route(int $userId, string $input): array
    {
        if ($input === 'wizard:cancel') {
            $this->sessions->delete($userId);

            return ['text' => 'Wizard cancelled.', 'keyboard' => []];
        }

        if (str_starts_with($input, 'wizard:')) {
            return $this->handleCallback($userId, $input);
        }

        return $this->handleCommand($userId, $input);
    }

    private function handleCommand(int $userId, string $command): array
    {
        $type = match (true) {
            str_contains($command, '/import') => 'import',
            str_contains($command, '/export') => 'export',
            default => '',
        };

        if ($type === '' || ! isset($this->wizards[$type])) {
            return ['text' => 'Unknown wizard command.', 'keyboard' => []];
        }

        if ($this->sessions->hasActive($userId)) {
            return ['text' => 'Active wizard in progress. Cancel first with /cancel.', 'keyboard' => []];
        }

        return $this->wizards[$type]->start($userId);
    }

    private function handleCallback(int $userId, string $callback): array
    {
        $parts = explode(':', $callback);

        if (count($parts) < 3) {
            return ['text' => 'Invalid callback.', 'keyboard' => []];
        }

        $type = $parts[1];

        if (! isset($this->wizards[$type])) {
            return ['text' => 'Unknown wizard.', 'keyboard' => []];
        }

        $input = $parts[2];

        return $this->wizards[$type]->handle($userId, $input);
    }
}
