<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Application;

use InvalidArgumentException;

/**
 * Resolves command/query → handler; explicit binding in service provider.
 */
final class ApplicationServiceBus
{
    /** @var array<class-string, object> */
    private array $handlers = [];

    /**
     * @param  class-string  $commandClass
     */
    public function register(string $commandClass, object $handler): void
    {
        $this->handlers[$commandClass] = $handler;
    }

    public function dispatch(ApplicationCommand $command): CommandResult
    {
        $handler = $this->handlers[get_class($command)] ?? null;

        if ($handler === null) {
            throw new InvalidArgumentException('No handler registered for '.get_class($command));
        }

        return $handler->handle($command);
    }

    public function query(ApplicationQuery $query): QueryResult
    {
        $handler = $this->handlers[get_class($query)] ?? null;

        if ($handler === null) {
            throw new InvalidArgumentException('No handler registered for '.get_class($query));
        }

        return $handler->handle($query);
    }
}
