<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Bot\Wizard;

/**
 * Base class for multi-step bot wizard flows (plan §§10.12 п.11, 11.10).
 * Each wizard implements step handlers that return response arrays.
 */
abstract class BaseWizard
{
    public function __construct(
        protected readonly WizardSessionStore $sessions,
    ) {
    }

    /**
     * Start a new wizard session for a user.
     */
    abstract public function start(int $userId): array;

    /**
     * Handle a step transition (callback data or text input).
     */
    abstract public function handle(int $userId, string $input): array;

    /**
     * Cancel the current wizard session.
     */
    public function cancel(int $userId): array
    {
        $this->sessions->delete($userId);

        return ['text' => 'Wizard cancelled.', 'keyboard' => []];
    }

    protected function session(int $userId): ?WizardSession
    {
        return $this->sessions->get($userId);
    }

    protected function saveAndReply(WizardSession $session, string $text, array $keyboard = []): array
    {
        $this->sessions->save($session);

        return ['text' => $text, 'keyboard' => $keyboard];
    }
}
