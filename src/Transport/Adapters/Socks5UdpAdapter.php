<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Transport\Adapters;

use BAGArt\ProxyOperations\Domain\Identity\ProxyProtocol;
use BAGArt\ProxyOperations\Transport\ProxyConfig;
use BAGArt\ProxyOperations\Transport\SocksOptions;
use RuntimeException;

/**
 * UDP ASSOCIATE through SOCKS5 proxy (plan §§11.5, 11.39 п.5).
 *
 * Establishes a TCP control connection, sends UDP ASSOCIATE request,
 * receives the relay address:port, then sends/receives UDP datagrams
 * through the relay.
 *
 * Scope: capability probe only — whether the proxy supports UDP ASSOCIATE.
 * Full UDP relay session management is a higher-layer concern.
 */
final class Socks5UdpAdapter implements UdpAssociateProbeContract
{
    private const SOCKS5_VERSION = 0x05;

    private const SOCKS5_CMD_UDP_ASSOCIATE = 0x03;

    private const SOCKS5_RSV = 0x00;

    private const SOCKS5_ATYP_IPV4 = 0x01;

    private const SOCKS5_ATYP_DOMAIN = 0x03;

    private const SOCKS5_ATYP_IPV6 = 0x04;

    private const SOCKS5_METHOD_NO_AUTH = 0x00;

    private const SOCKS5_METHOD_USER_PASS = 0x02;

    private const SOCKS5_METHOD_NO_ACCEPTABLE = 0xFF;

    private const SOCKS5_AUTH_SUCCESS = 0x00;

    private const SOCKS5_STATUS_SUCCESS = 0x00;

    private const SUPPORTED_SCHEMES = [
        ProxyProtocol::Socks5,
        ProxyProtocol::Socks5h,
    ];

    /**
     * Establish UDP ASSOCIATE and return relay information.
     *
     * Steps:
     * 1. TCP connect to SOCKS5 proxy.
     * 2. SOCKS5 handshake (auth via CredentialChannel).
     * 3. Send UDP ASSOCIATE request (cmd=0x03).
     * 4. Receive relay address:port from response.
     * 5. Return UdpAssociateResult with relay address and success/failure.
     *
     * @throws TransportConnectionException if UDP ASSOCIATE fails.
     * @throws TransportAuthException if auth fails.
     */
    public function associate(
        ProxyConfig $config,
        string $targetHost,
        int $targetPort,
    ): UdpAssociateResult {
        $this->validateScheme($config);

        $proxyHost = $config->host;
        $proxyPort = $config->port;

        $timerStart = hrtime(true);

        try {
            $stream = $this->tcpConnect($proxyHost, $proxyPort);
            $timings = $this->buildTimings($timerStart, 'tcpConnect');

            $this->greeting($stream, $proxyHost, $proxyPort);
            $timings['greeting'] = $this->elapsedMs($timerStart);

            $relay = $this->sendUdpAssociate($stream, $targetHost, $targetPort, $proxyHost, $proxyPort);
            $timings['udpAssociate'] = $this->elapsedMs($timerStart);

            fclose($stream);

            return new UdpAssociateResult(
                supported: true,
                relayHost: $relay['host'],
                relayPort: $relay['port'],
                error: null,
                timingsMs: $timings,
            );
        } catch (TransportConnectionException|TransportAuthException $e) {
            return new UdpAssociateResult(
                supported: false,
                relayHost: null,
                relayPort: null,
                error: $e->getMessage(),
                timingsMs: [],
            );
        } catch (RuntimeException $e) {
            return new UdpAssociateResult(
                supported: false,
                relayHost: null,
                relayPort: null,
                error: $e->getMessage(),
                timingsMs: [],
            );
        }
    }

    /**
     * Send a UDP datagram through the established relay and wait for response.
     */
    public function sendDatagram(
        UdpRelayHandle $relay,
        string $data,
        string $targetHost,
        int $targetPort,
    ): UdpDatagramResult {
        $targetPacked = @inet_pton($targetHost);

        if ($targetPacked === false) {
            return new UdpDatagramResult(
                success: false,
                response: null,
                error: "Invalid target host: {$targetHost}",
                latencyMs: 0.0,
            );
        }

        $atyp = strlen($targetPacked) === 4
            ? self::SOCKS5_ATYP_IPV4
            : self::SOCKS5_ATYP_IPV6;

        $header = pack('nnC', 0x0000, 0x0000, $atyp).$targetPacked.pack('n', $targetPort);
        $datagram = $header.$data;

        $start = hrtime(true);

        $bytesSent = @fwrite($relay->udpSocket, $datagram);

        if ($bytesSent === false || $bytesSent < strlen($datagram)) {
            return new UdpDatagramResult(
                success: false,
                response: null,
                error: 'Failed to send UDP datagram through relay.',
                latencyMs: $this->elapsedMs($start),
            );
        }

        $response = @fread($relay->udpSocket, 65535);
        $latency = $this->elapsedMs($start);

        if ($response === false || $response === '') {
            return new UdpDatagramResult(
                success: false,
                response: null,
                error: 'No response from UDP relay.',
                latencyMs: $latency,
            );
        }

        $responsePayload = $this->stripUdpHeader($response);

        return new UdpDatagramResult(
            success: true,
            response: $responsePayload,
            error: null,
            latencyMs: $latency,
        );
    }

    /**
     * Close the UDP relay and release the TCP control connection.
     */
    public function close(): void
    {
        // Stateless adapter — each associate() opens/closes its own stream.
    }

    private function validateScheme(ProxyConfig $config): void
    {
        if (! in_array($config->scheme, self::SUPPORTED_SCHEMES, true)) {
            throw new \InvalidArgumentException(
                "Socks5UdpAdapter requires Socks5 or Socks5h scheme, '{$config->scheme->value}' given.",
            );
        }

        if (! $config->transportOptions instanceof SocksOptions) {
            throw new \InvalidArgumentException(
                'Socks5UdpAdapter requires SocksOptions.',
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
     * Send UDP ASSOCIATE request (cmd=0x03) and return the relay host:port.
     *
     * @param  resource  $stream
     * @return array{host: string, port: int}
     */
    private function sendUdpAssociate(
        mixed $stream,
        string $targetHost,
        int $targetPort,
        string $proxyHost,
        int $proxyPort,
    ): array {
        $targetPacked = @inet_pton($targetHost);

        if ($targetPacked !== false) {
            $atyp = strlen($targetPacked) === 4
                ? self::SOCKS5_ATYP_IPV4
                : self::SOCKS5_ATYP_IPV6;

            $packet = pack(
                'CCCN',
                self::SOCKS5_VERSION,
                self::SOCKS5_CMD_UDP_ASSOCIATE,
                self::SOCKS5_RSV,
                $atyp,
            ).$targetPacked.pack('n', $targetPort);
        } else {
            $packet = pack(
                'CCCC',
                self::SOCKS5_VERSION,
                self::SOCKS5_CMD_UDP_ASSOCIATE,
                self::SOCKS5_RSV,
                self::SOCKS5_ATYP_DOMAIN,
            ).chr(strlen($targetHost)).$targetHost.pack('n', $targetPort);
        }

        $bytesWritten = @fwrite($stream, $packet);

        if ($bytesWritten === false || $bytesWritten === 0) {
            fclose($stream);

            throw new TransportConnectionException(
                "Failed to send UDP ASSOCIATE request to {$proxyHost}:{$proxyPort}.",
            );
        }

        $response = @fread($stream, 20);

        if ($response === false || strlen($response) < 10) {
            fclose($stream);

            throw new TransportConnectionException(
                "No response to UDP ASSOCIATE from {$proxyHost}:{$proxyPort}.",
            );
        }

        $status = ord($response[1]);

        if ($status !== self::SOCKS5_STATUS_SUCCESS) {
            fclose($stream);

            $this->throwForStatus($status, $proxyHost, $proxyPort);
        }

        return $this->extractRelayFromResponse($response);
    }

    /**
     * @return array{host: string, port: int}
     */
    private function extractRelayFromResponse(string $response): array
    {
        $atyp = ord($response[3]);

        if ($atyp === self::SOCKS5_ATYP_IPV4) {
            return [
                'host' => inet_ntop(substr($response, 4, 4)),
                'port' => unpack('n', substr($response, 8, 2))[1],
            ];
        }

        if ($atyp === self::SOCKS5_ATYP_IPV6) {
            return [
                'host' => inet_ntop(substr($response, 4, 16)),
                'port' => unpack('n', substr($response, 20, 2))[1],
            ];
        }

        if ($atyp === self::SOCKS5_ATYP_DOMAIN) {
            $domainLen = ord($response[4]);

            return [
                'host' => substr($response, 5, $domainLen),
                'port' => unpack('n', substr($response, 5 + $domainLen, 2))[1],
            ];
        }

        throw new TransportConnectionException(
            'Invalid address type in UDP ASSOCIATE response.',
        );
    }

    private function throwForStatus(int $status, string $proxyHost, int $proxyPort): never
    {
        match ($status) {
            0x01 => throw new TransportConnectionException(
                "SOCKS5 general failure from {$proxyHost}:{$proxyPort}.",
            ),
            0x02 => throw new TransportConnectionException(
                "SOCKS5 connection not allowed from {$proxyHost}:{$proxyPort}.",
            ),
            0x03 => throw new TransportConnectionException(
                "SOCKS5 network unreachable from {$proxyHost}:{$proxyPort}.",
            ),
            0x04 => throw new TransportConnectionException(
                "SOCKS5 host unreachable from {$proxyHost}:{$proxyPort}.",
            ),
            0x05 => throw new TransportConnectionException(
                "SOCKS5 connection refused from {$proxyHost}:{$proxyPort}.",
            ),
            0x07 => throw new TransportConnectionException(
                "SOCKS5 command not supported from {$proxyHost}:{$proxyPort}.",
            ),
            default => throw new TransportConnectionException(
                'SOCKS5 unknown UDP ASSOCIATE status 0x'.dechex($status)." from {$proxyHost}:{$proxyPort}.",
            ),
        };
    }

    private function stripUdpHeader(string $response): string
    {
        if (strlen($response) < 4) {
            return $response;
        }

        $atyp = ord($response[3]);

        return match ($atyp) {
            self::SOCKS5_ATYP_IPV4 => substr($response, 10),
            self::SOCKS5_ATYP_IPV6 => substr($response, 22),
            self::SOCKS5_ATYP_DOMAIN => substr($response, 5 + ord($response[4]) + 2),
            default => $response,
        };
    }

    private static function elapsedMs(float $start): float
    {
        return (hrtime(true) - $start) / 1_000_000;
    }

    /**
     * @return array<string, float>
     */
    private function buildTimings(float $start, string $key): array
    {
        return [$key => self::elapsedMs($start)];
    }
}
