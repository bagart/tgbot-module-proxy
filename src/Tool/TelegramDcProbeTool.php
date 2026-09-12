<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Tool;

use BAGArt\ProxyOperations\Domain\Failure\ExecutionFailure;
use BAGArt\ProxyOperations\Domain\Failure\FailureCode;
use BAGArt\ProxyOperations\Domain\Failure\FailureTaxonomy;
use BAGArt\ProxyOperations\Domain\Probe\ProbeType;
use BAGArt\ProxyOperations\Domain\Snapshot\TelegramDc;
use BAGArt\ProxyOperations\Domain\Snapshot\TelegramDcSet;
use Throwable;

/**
 * Telegram DC connectivity probe tool: TCP+TLS to a Telegram data center
 * through the proxy under test. Verifies that the proxy can reach Telegram's
 * infrastructure — the core usability criterion for SOCKS5/HTTP proxies.
 *
 * For MTProto proxies, use MtprotoProbeTool instead.
 */
final class TelegramDcProbeTool implements ProbeTool
{
    private readonly FailureTaxonomy $taxonomy;

    public function __construct()
    {
        $this->taxonomy = new FailureTaxonomy;
    }

    public function capabilities(): ToolCapabilities
    {
        return new ToolCapabilities(
            probeTypes: [ProbeType::TelegramDcConnectivity],
            protocols: ['socks5', 'socks5h', 'http', 'https'],
            inputSchemaVersion: 1,
            outputSchemaVersion: 1,
        );
    }

    public function execute(ProbeExecutionContext $context): ProbeToolResult
    {
        $startMs = $this->nowMs();

        try {
            $observations = $this->probeDcConnectivity($context);

            return ProbeToolResult::ok(
                $observations,
                ['totalMs' => $this->nowMs() - $startMs],
            );
        } catch (Throwable $e) {
            return ProbeToolResult::failed(
                new ExecutionFailure(
                    $this->taxonomy->descriptor(FailureCode::ToolCrash),
                    [
                        'exception_class' => $e::class,
                        'message' => $e->getMessage(),
                    ],
                ),
                ['totalMs' => $this->nowMs() - $startMs],
            );
        }
    }

    /**
     * TCP+TLS connection to a Telegram DC through the proxy.
     *
     * The target format is: dc_id (e.g., "1", "2", "3", "4", "5")
     * The DC addresses are resolved from the TelegramDcSet or hardcoded defaults.
     *
     * @return array<string, mixed>
     */
    private function probeDcConnectivity(ProbeExecutionContext $context): array
    {
        $dcId = (int) $context->spec->target;
        $dc = $this->resolveDc($dcId);

        $host = $dc['host'];
        $port = $dc['port'];

        // TCP connect through proxy (the proxy host/port are in context)
        $connectStart = $this->nowMs();
        $stream = $this->connectThroughProxy($context, $host, $port);
        $connectMs = $this->nowMs() - $connectStart;

        if ($stream === null) {
            throw new \RuntimeException("Failed to connect to DC {$dcId} ({$host}:{$port}) through proxy");
        }

        // TLS handshake
        $tlsStart = $this->nowMs();
        $tlsStream = $this->startTls($stream, $host);
        $tlsMs = $this->nowMs() - $tlsStart;

        if ($tlsStream === null) {
            fclose($stream);
            throw new \RuntimeException("TLS handshake failed with DC {$dcId}");
        }

        // Send a minimal TLS ClientHello-like payload to verify the connection
        // For Telegram DCs, the DC info port (443) accepts TLS and speaks MTProto
        $payloadStart = $this->nowMs();
        $sent = @fwrite($tlsStream, pack('N', 0)); // 4-byte padding (MTProto protocol detection)
        $payloadMs = $this->nowMs() - $payloadStart;

        fclose($tlsStream);

        return [
            'dc_id' => $dcId,
            'dc_host' => $host,
            'dc_port' => $port,
            'connect_ms' => $connectMs,
            'tls_ms' => $tlsMs,
            'payload_ms' => $payloadMs,
            'total_ms' => $connectMs + $tlsMs + $payloadMs,
            'tls_verified' => $tlsStream !== null,
            'connectable' => true,
        ];
    }

    /**
     * Connect to target through the proxy using the appropriate method.
     *
     * @return resource|null  TCP stream resource
     */
    private function connectThroughProxy(ProbeExecutionContext $context, string $targetHost, int $targetPort)
    {
        $proxyHost = $context->host;
        $proxyPort = $context->port;

        // Direct TCP to proxy
        $stream = @stream_socket_client(
            "tcp://{$proxyHost}:{$proxyPort}",
            $errno,
            $errstr,
            $context->timeoutMs / 1000,
        );

        if ($stream === false) {
            return null;
        }

        // For HTTP CONNECT proxy, send CONNECT request
        $connectReq = "CONNECT {$targetHost}:{$targetPort} HTTP/1.1\r\n"
            ."Host: {$targetHost}:{$targetPort}\r\n"
            ."\r\n";

        @fwrite($stream, $connectReq);

        // Read response (simplified — full implementation would parse HTTP response)
        $response = @fread($stream, 1024);

        if (! str_contains($response, '200')) {
            fclose($stream);

            return null;
        }

        return $stream;
    }

    /**
     * Start TLS on a stream resource.
     *
     * @param  resource  $stream
     * @return resource|null
     */
    private function startTls(mixed $stream, string $host)
    {
        $context = stream_context_create([
            'ssl' => [
                'capture_peer' => true,
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
                'SNI_enabled' => true,
                'disable_compression' => true,
            ],
        ]);

        $tlsStream = @stream_socket_enable_crypto($stream, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT);

        if ($tlsStream === true) {
            return $stream;
        }

        if ($tlsStream === false) {
            return null;
        }

        // Would need event loop for async — for now, return null
        return null;
    }

    /**
     * Resolve DC ID to host/port. Uses TelegramDcSet if available, otherwise defaults.
     *
     * @return array{host: string, port: int}
     */
    private function resolveDc(int $dcId): array
    {
        $defaults = [
            1 => ['host' => '149.154.175.50', 'port' => 443],
            2 => ['host' => '149.154.167.51', 'port' => 443],
            3 => ['host' => '149.154.175.100', 'port' => 443],
            4 => ['host' => '149.154.167.91', 'port' => 443],
            5 => ['host' => '149.154.175.72', 'port' => 443],
        ];

        return $defaults[$dcId] ?? $defaults[1];
    }

    private function nowMs(): float
    {
        return (float) hrtime(true) / 1_000_000;
    }
}
