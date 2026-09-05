<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Transport\Adapters;

use BAGArt\ProxyOperations\Domain\Identity\ProxyProtocol;
use BAGArt\ProxyOperations\Transport\ProxyConfig;
use BAGArt\ProxyOperations\Transport\SocksDnsMode;
use BAGArt\ProxyOperations\Transport\SocksOptions;

/**
 * SOCKS5/SOCKS5h tunnel adapter (plan §11.39 п.3).
 *
 * SOCKS5 handshake:
 *   1. Greeting: version=0x05, nauth=1, methods=[0x00, 0x02]
 *   2. Server selects method; if 0x02: user/pass sub-negotiation
 *   3. Connect request: version=0x05, cmd=0x01, rsv=0x00, atyp, addr, port
 *   4. Response: status=0x00 (success)
 *
 * SOCKS5h: atyp=0x03 (domain) so proxy resolves DNS (ProxyDns mode).
 */
final class Socks5Adapter implements TransportAdapterContract
{
    private const SUPPORTED_SCHEMES = [
        ProxyProtocol::Socks5,
        ProxyProtocol::Socks5h,
    ];

    private const SOCKS5_VERSION = 0x05;

    private const SOCKS5_CMD_CONNECT = 0x01;

    private const SOCKS5_RSV = 0x00;

    private const SOCKS5_ATYP_IPV4 = 0x01;

    private const SOCKS5_ATYP_DOMAIN = 0x03;

    private const SOCKS5_ATYP_IPV6 = 0x04;

    private const SOCKS5_METHOD_NO_AUTH = 0x00;

    private const SOCKS5_METHOD_USER_PASS = 0x02;

    private const SOCKS5_METHOD_NO_ACCEPTABLE = 0xFF;

    private const SOCKS5_AUTH_SUCCESS = 0x00;

    private const SOCKS5_STATUS_SUCCESS = 0x00;

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

        $this->greeting($stream, $proxyHost, $proxyPort);

        $this->connectRequest(
            $stream,
            $targetHost,
            $targetPort,
            $config,
            $proxyHost,
            $proxyPort,
        );

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
                "Socks5Adapter requires Socks5 or Socks5h scheme, '{$config->scheme->value}' given.",
            );
        }

        if (! $config->transportOptions instanceof SocksOptions) {
            throw new \InvalidArgumentException(
                'Socks5Adapter requires SocksOptions.',
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
                "Cannot connect to SOCKS5 proxy {$host}:{$port}: {$errstr}",
                $errno,
            );
        }

        return $stream;
    }

    /**
     * SOCKS5 greeting: propose no-auth and user/pass methods.
     *
     * @param  resource  $stream
     */
    private function greeting(
        mixed $stream,
        string $proxyHost,
        int $proxyPort,
    ): void {
        $greeting = pack(
            'CCC',
            self::SOCKS5_VERSION,
            2,
            self::SOCKS5_METHOD_NO_AUTH,
        ).chr(self::SOCKS5_METHOD_USER_PASS);

        $bytesWritten = @fwrite($stream, $greeting);

        if ($bytesWritten === false || $bytesWritten === 0) {
            fclose($stream);

            throw new TransportConnectionException(
                "Failed to send SOCKS5 greeting to {$proxyHost}:{$proxyPort}.",
            );
        }

        $response = @fread($stream, 2);

        if ($response === false || strlen($response) < 2) {
            fclose($stream);

            throw new TransportConnectionException(
                "No response to SOCKS5 greeting from {$proxyHost}:{$proxyPort}.",
            );
        }

        $selectedMethod = ord($response[1]);

        if ($selectedMethod === self::SOCKS5_METHOD_NO_ACCEPTABLE) {
            fclose($stream);

            throw new TransportAuthException(
                "SOCKS5 proxy {$proxyHost}:{$proxyPort} rejected all authentication methods.",
            );
        }

        if ($selectedMethod === self::SOCKS5_METHOD_USER_PASS) {
            $this->userPassSubNegotiation($stream, $proxyHost, $proxyPort);
        }
    }

    /**
     * SOCKS5 username/password sub-negotiation (RFC 1929).
     *
     * @param  resource  $stream
     */
    private function userPassSubNegotiation(
        mixed $stream,
        string $proxyHost,
        int $proxyPort,
    ): void {
        $username = 'proxy';
        $password = '';

        $packet = chr(0x01)
            .chr(strlen($username))
            .$username
            .chr(strlen($password))
            .$password;

        $bytesWritten = @fwrite($stream, $packet);

        if ($bytesWritten === false || $bytesWritten === 0) {
            fclose($stream);

            throw new TransportConnectionException(
                "Failed to send SOCKS5 auth to {$proxyHost}:{$proxyPort}.",
            );
        }

        $response = @fread($stream, 2);

        if ($response === false || strlen($response) < 2) {
            fclose($stream);

            throw new TransportConnectionException(
                "No response to SOCKS5 auth from {$proxyHost}:{$proxyPort}.",
            );
        }

        $status = ord($response[1]);

        if ($status !== self::SOCKS5_AUTH_SUCCESS) {
            fclose($stream);

            throw new TransportAuthException(
                "SOCKS5 authentication failed for {$proxyHost}:{$proxyPort}.",
            );
        }
    }

    /**
     * SOCKS5 CONNECT request.
     *
     * @param  resource  $stream
     */
    private function connectRequest(
        mixed $stream,
        string $targetHost,
        int $targetPort,
        ProxyConfig $config,
        string $proxyHost,
        int $proxyPort,
    ): void {
        $scheme = $config->scheme;
        $isSocks5h = $scheme === ProxyProtocol::Socks5h;
        $dnsMode = $config->transportOptions instanceof SocksOptions
            ? $config->transportOptions->dnsMode
            : SocksDnsMode::Local;

        if ($isSocks5h || $dnsMode === SocksDnsMode::ProxyDns) {
            $packet = $this->buildDomainPacket($targetHost, $targetPort);
        } else {
            $isIp = filter_var($targetHost, FILTER_VALIDATE_IP) !== false;

            if ($isIp) {
                $packed = @inet_pton($targetHost);

                if ($packed === false) {
                    throw new TransportConnectionException(
                        "Failed to pack target IP: {$targetHost}.",
                    );
                }

                $atyp = strlen($packed) === 4
                    ? self::SOCKS5_ATYP_IPV4
                    : self::SOCKS5_ATYP_IPV6;

                $packet = pack(
                    'CCCN',
                    self::SOCKS5_VERSION,
                    self::SOCKS5_CMD_CONNECT,
                    self::SOCKS5_RSV,
                    $atyp,
                ).$packed.pack('n', $targetPort);
            } else {
                $packet = $this->buildDomainPacket($targetHost, $targetPort);
            }
        }

        $bytesWritten = @fwrite($stream, $packet);

        if ($bytesWritten === false || $bytesWritten === 0) {
            fclose($stream);

            throw new TransportConnectionException(
                "Failed to send SOCKS5 CONNECT request to {$proxyHost}:{$proxyPort}.",
            );
        }

        $this->waitForConnectResponse($stream, $proxyHost, $proxyPort);
    }

    private function buildDomainPacket(string $targetHost, int $targetPort): string
    {
        return pack(
            'CCCC',
            self::SOCKS5_VERSION,
            self::SOCKS5_CMD_CONNECT,
            self::SOCKS5_RSV,
            self::SOCKS5_ATYP_DOMAIN,
        ).chr(strlen($targetHost)).$targetHost.pack('n', $targetPort);
    }

    /**
     * @param  resource  $stream
     */
    private function waitForConnectResponse(
        mixed $stream,
        string $proxyHost,
        int $proxyPort,
    ): void {
        $response = @fread($stream, 10);

        if ($response === false || strlen($response) < 2) {
            fclose($stream);

            throw new TransportConnectionException(
                "No response to SOCKS5 CONNECT from {$proxyHost}:{$proxyPort}.",
            );
        }

        $status = ord($response[1]);

        if ($status === self::SOCKS5_STATUS_SUCCESS) {
            return;
        }

        fclose($stream);

        match (true) {
            $status === 0x01 => throw new TransportConnectionException(
                "SOCKS5 general failure from {$proxyHost}:{$proxyPort}.",
            ),
            $status === 0x02 => throw new TransportConnectionException(
                "SOCKS5 connection not allowed from {$proxyHost}:{$proxyPort}.",
            ),
            $status === 0x03 => throw new TransportConnectionException(
                "SOCKS5 network unreachable from {$proxyHost}:{$proxyPort}.",
            ),
            $status === 0x04 => throw new TransportConnectionException(
                "SOCKS5 host unreachable from {$proxyHost}:{$proxyPort}.",
            ),
            $status === 0x05 => throw new TransportConnectionException(
                "SOCKS5 connection refused from {$proxyHost}:{$proxyPort}.",
            ),
            $status === 0x06 => throw new TransportConnectionException(
                "SOCKS5 TTL expired from {$proxyHost}:{$proxyPort}.",
            ),
            $status === 0x07 => throw new TransportConnectionException(
                "SOCKS5 command not supported from {$proxyHost}:{$proxyPort}.",
            ),
            $status === 0x08 => throw new TransportConnectionException(
                "SOCKS5 address type not supported from {$proxyHost}:{$proxyPort}.",
            ),
            default => throw new TransportConnectionException(
                'SOCKS5 unknown status 0x'.dechex($status)." from {$proxyHost}:{$proxyPort}.",
            ),
        };
    }
}
