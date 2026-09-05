<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Transport;

use BAGArt\ProxyOperations\Domain\Identity\ProxyProtocol;
use BAGArt\ProxyOperations\Transport\Adapters\TransportAdapterContract;
use BAGArt\ProxyOperations\Transport\Adapters\TransportConnectionException;

/**
 * Maps ProxyProtocol → TransportAdapterContract (plan §11.39 п.20: domain
 * calls ProbeTool contract; the adapter — CLI or HTTP-runner — is an
 * implementation detail, identical across domain and worker).
 */
final class TransportAdapterResolver
{
    /** @var array<string, TransportAdapterContract> */
    private array $adapters = [];

    public function register(ProxyProtocol $protocol, TransportAdapterContract $adapter): void
    {
        $this->adapters[$protocol->value] = $adapter;
    }

    /**
     * @throws TransportConnectionException When protocol is not registered.
     */
    public function resolve(ProxyProtocol $protocol): TransportAdapterContract
    {
        $adapter = $this->adapters[$protocol->value] ?? null;

        if ($adapter === null) {
            throw new TransportConnectionException(
                "No transport adapter registered for protocol '{$protocol->value}'.",
            );
        }

        return $adapter;
    }

    /**
     * @return list<ProxyProtocol>
     */
    public function supported(): array
    {
        $protocols = [];

        foreach ($this->adapters as $value => $_adapter) {
            $protocols[] = ProxyProtocol::from($value);
        }

        return $protocols;
    }
}
