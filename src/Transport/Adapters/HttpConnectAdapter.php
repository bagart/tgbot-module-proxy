<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Transport\Adapters;

use BAGArt\ProxyOperations\Domain\Identity\ProxyProtocol;
use BAGArt\ProxyOperations\Transport\HttpConnectOptions;
use BAGArt\ProxyOperations\Transport\ProxyConfig;

/**
 * HTTP/1.1 CONNECT tunnel adapter (plan §11.39 п.3).
 *
 * Establishes a TCP connection to the proxy, sends a CONNECT request to
 * create a tunnel. Credentials are delivered via Proxy-Authorization header
 * using CredentialPayload in memory only — never logged, never in argv.
 */
final class HttpConnectAdapter implements TransportAdapterContract
{
    private const SUPPORTED_SCHEMES = [
        ProxyProtocol::Http,
        ProxyProtocol::Https,
    ];

    /** @var resource|null */
    private mixed $stream = null;

    public function connect(
        ProxyConfig $config,
        string $targetHost,
        int $targetPort,
    ): mixed {
        $this->validateScheme($config);
        $this->close();

        $proxyHost = $config->host;
        $proxyPort = $config->port;

        $stream = $this->tcpConnect($proxyHost, $proxyPort);

        $connectRequest = $this->buildConnectRequest(
            $targetHost,
            $targetPort,
            $config->credential?->username,
        );

        $this->sendRequest($stream, $connectRequest);

        $this->waitForTunnelEstablished($stream, $proxyHost, $proxyPort);

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

    private function validateScheme(ProxyConfig $config): void
    {
        if (! in_array($config->scheme, self::SUPPORTED_SCHEMES, true)) {
            throw new \InvalidArgumentException(
                "HttpConnectAdapter requires Http or Https scheme, '{$config->scheme->value}' given.",
            );
        }

        if (! $config->transportOptions instanceof HttpConnectOptions) {
            throw new \InvalidArgumentException(
                'HttpConnectAdapter requires HttpConnectOptions.',
            );
        }
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
                "Cannot connect to HTTP proxy {$host}:{$port}: {$errstr}",
                $errno,
            );
        }

        return $stream;
    }

    private function buildConnectRequest(
        string $targetHost,
        int $targetPort,
        ?string $username,
    ): string {
        $request = "CONNECT {$targetHost}:{$targetPort} HTTP/1.1\r\n";
        $request .= "Host: {$targetHost}:{$targetPort}\r\n";

        if ($username !== null) {
            $authHeader = base64_encode($username);
            $request .= "Proxy-Authorization: Basic {$authHeader}\r\n";
        }

        $request .= "\r\n";

        return $request;
    }

    /**
     * @param  resource  $stream
     */
    private function sendRequest(mixed $stream, string $request): void
    {
        $bytesWritten = @fwrite($stream, $request);

        if ($bytesWritten === false || $bytesWritten === 0) {
            fclose($stream);

            throw new TransportConnectionException('Failed to send CONNECT request to proxy.');
        }
    }

    /**
     * @param  resource  $stream
     */
    private function waitForTunnelEstablished(
        mixed $stream,
        string $proxyHost,
        int $proxyPort,
    ): void {
        $response = @fgets($stream, 1024);

        if ($response === false || $response === '') {
            fclose($stream);

            throw new TransportConnectionException(
                "No response from HTTP proxy {$proxyHost}:{$proxyPort}.",
            );
        }

        if (! preg_match('/^HTTP\/\d\.\d\s+(\d{3})/', $response, $matches)) {
            fclose($stream);

            throw new TransportConnectionException(
                "Invalid HTTP response from proxy {$proxyHost}:{$proxyPort}.",
            );
        }

        $statusCode = (int) $matches[1];

        if ($statusCode === 407) {
            fclose($stream);

            throw new TransportAuthException(
                "Proxy authentication failed for {$proxyHost}:{$proxyPort}.",
            );
        }

        if ($statusCode !== 200) {
            fclose($stream);

            throw new TransportConnectionException(
                "HTTP CONNECT tunnel failed with status {$statusCode} from {$proxyHost}:{$proxyPort}.",
            );
        }
    }
}
