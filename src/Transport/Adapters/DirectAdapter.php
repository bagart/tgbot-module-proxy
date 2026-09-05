<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Transport\Adapters;

use BAGArt\ProxyOperations\Transport\ProxyConfig;
use BAGArt\ProxyOperations\Transport\TlsOptions;

/**
 * Direct TCP connection — no proxy tunneling. Used for judge fetches and
 * direct target connections (plan §11.39 п.3).
 *
 * Optional TLS upgrade if TlsOptions.verifyPeer is set.
 */
final class DirectAdapter implements TransportAdapterContract
{
    /** @var resource|null */
    private mixed $stream = null;

    public function connect(
        ProxyConfig $config,
        string $targetHost,
        int $targetPort,
    ): mixed {
        $this->close();

        $host = $config->host;
        $port = $config->port;

        $stream = $this->tcpConnect($host, $port);

        if ($config->tls->verifyPeer || $config->tls->allowSelfSigned) {
            $stream = $this->upgradeToTls($stream, $host, $config->tls);
        }

        $this->stream = $stream;

        return $stream;
    }

    public function close(): void
    {
        if ($this->stream !== null && is_resource($this->stream)) {
            fclose($this->stream);
        }

        $this->stream = null;
    }

    /**
     * @return resource
     */
    private function tcpConnect(string $host, int $port): mixed
    {
        $errno = 0;
        $errstr = '';

        $stream = @stream_socket_client(
            "tcp://{$host}:{$port}",
            $errno,
            $errstr,
            10.0,
        );

        if ($stream === false) {
            throw new TransportConnectionException(
                "Direct connection to {$host}:{$port} failed: {$errstr}",
                $errno,
            );
        }

        return $stream;
    }

    /**
     * @param  resource  $stream
     * @return resource
     */
    private function upgradeToTls(mixed $stream, string $host, TlsOptions $tls): mixed
    {
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => $tls->verifyPeer,
                'allow_self_signed' => $tls->allowSelfSigned,
                'cafile' => $tls->caBundlePath,
                'peer_name' => $host,
            ],
        ]);

        $tlsStream = @stream_socket_enable_crypto($stream, true, STREAM_CRYPTO_METHOD_TLS_CLIENT, $context);

        if ($tlsStream === false) {
            fclose($stream);

            throw new TransportConnectionException("TLS upgrade to {$host} failed.");
        }

        return $tlsStream;
    }
}
