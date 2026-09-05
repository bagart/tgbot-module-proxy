<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Transport\Adapters;

use BAGArt\ProxyOperations\Domain\Identity\ProxyProtocol;
use BAGArt\ProxyOperations\Transport\ProxyConfig;

/**
 * SOCKS4/SOCKS4a tunnel adapter (plan §11.39 п.3).
 *
 * SOCKS4 handshake: version=0x01, command=0x01 (CONNECT), port (2 bytes
 * big-endian), IP (4 bytes), USERID (optional null-terminated string),
 * 0x00 terminator.
 *
 * SOCKS4a: when target is a domain (not IP), uses 0.0.0.1 as the IP field
 * followed by the null-terminated domain string after the USERID.
 */
final class Socks4Adapter implements TransportAdapterContract
{
    private const SUPPORTED_SCHEMES = [
        ProxyProtocol::Socks4,
        ProxyProtocol::Socks4a,
    ];

    private const SOCKS4_VERSION = 0x01;

    private const SOCKS4_CMD_CONNECT = 0x01;

    private const SOCKS4_GRANTED = 0x5A;

    private const SOCKS4_REJECTED_FAILURE = 0x5B;

    private const SOCKS4_REJECTED_IDENTD = 0x5C;

    private const SOCKS4_REJECTED_USERID = 0x5D;

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

        $handshake = $this->buildHandshake(
            $targetHost,
            $targetPort,
            $config->credential?->username,
            $config->scheme,
        );

        $this->sendRequest($stream, $handshake);

        $this->waitForResponse($stream, $proxyHost, $proxyPort);

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
                "Socks4Adapter requires Socks4 or Socks4a scheme, '{$config->scheme->value}' given.",
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
                "Cannot connect to SOCKS4 proxy {$host}:{$port}: {$errstr}",
                $errno,
            );
        }

        return $stream;
    }

    /**
     * Build the SOCKS4/4a handshake packet.
     */
    private function buildHandshake(
        string $targetHost,
        int $targetPort,
        ?string $username,
        ProxyProtocol $scheme,
    ): string {
        $isSocks4a = $scheme === ProxyProtocol::Socks4a;
        $isIp = filter_var($targetHost, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;

        $packet = pack('CCn', self::SOCKS4_VERSION, self::SOCKS4_CMD_CONNECT, $targetPort);

        if ($isSocks4a && ! $isIp) {
            $packet .= pack('C4', 0, 0, 0, 1);
        } elseif ($isIp) {
            $packet .= inet_pton($targetHost);
        } else {
            $packet .= pack('C4', 0, 0, 0, 1);
        }

        $userId = $username ?? '';

        $packet .= $userId."\x00";

        if ($isSocks4a && ! $isIp) {
            $packet .= $targetHost."\x00";
        }

        return $packet;
    }

    /**
     * @param  resource  $stream
     */
    private function sendRequest(mixed $stream, string $request): void
    {
        $bytesWritten = @fwrite($stream, $request);

        if ($bytesWritten === false || $bytesWritten === 0) {
            fclose($stream);

            throw new TransportConnectionException('Failed to send SOCKS4 handshake.');
        }
    }

    /**
     * @param  resource  $stream
     */
    private function waitForResponse(
        mixed $stream,
        string $proxyHost,
        int $proxyPort,
    ): void {
        $response = @fread($stream, 8);

        if ($response === false || strlen($response) < 2) {
            fclose($stream);

            throw new TransportConnectionException(
                "No response from SOCKS4 proxy {$proxyHost}:{$proxyPort}.",
            );
        }

        $status = ord($response[1]);

        match ($status) {
            self::SOCKS4_GRANTED => null,
            self::SOCKS4_REJECTED_FAILURE => throw $this->authOrConnectionFailure(
                $stream,
                $proxyHost,
                $proxyPort,
                'SOCKS4 request rejected or failed',
            ),
            self::SOCKS4_REJECTED_IDENTD => throw $this->authOrConnectionFailure(
                $stream,
                $proxyHost,
                $proxyPort,
                'SOCKS4 client could not be identified',
            ),
            self::SOCKS4_REJECTED_USERID => throw $this->authOrConnectionFailure(
                $stream,
                $proxyHost,
                $proxyPort,
                'SOCKS4 user ID mismatch',
            ),
            default => throw $this->authOrConnectionFailure(
                $stream,
                $proxyHost,
                $proxyPort,
                'SOCKS4 unknown status 0x'.dechex($status),
            ),
        };
    }

    /**
     * @param  resource  $stream
     */
    private function authOrConnectionFailure(
        mixed $stream,
        string $proxyHost,
        int $proxyPort,
        string $reason,
    ): TransportAuthException|TransportConnectionException {
        fclose($stream);

        $isAuth = str_contains($reason, 'identified') || str_contains($reason, 'user ID');

        if ($isAuth) {
            return new TransportAuthException(
                "SOCKS4 auth failure for {$proxyHost}:{$proxyPort}: {$reason}",
            );
        }

        return new TransportConnectionException(
            "SOCKS4 connection failure for {$proxyHost}:{$proxyPort}: {$reason}",
        );
    }
}
