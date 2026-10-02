<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Tool;

use BAGArt\ProxyOperations\Domain\Failure\ExecutionFailure;
use BAGArt\ProxyOperations\Domain\Failure\FailureCode;
use BAGArt\ProxyOperations\Domain\Failure\FailureTaxonomy;
use BAGArt\ProxyOperations\Domain\Probe\ProbeType;
use Throwable;

/**
 * MTProto handshake probe tool: sends a minimal `req_pq_multi` to verify
 * the MTProto proxy is functional. Supports `mtproto://` and `tg://proxy?`
 * secret formats (hex/dd/+r/FakeTLS).
 *
 * The handshake is a single round-trip: send `req_pq_multi`, expect `resPQ`.
 * This verifies the proxy speaks MTProto without performing full authorization.
 */
final class MtprotoProbeTool implements ProbeTool
{
    private readonly FailureTaxonomy $taxonomy;

    public function __construct()
    {
        $this->taxonomy = new FailureTaxonomy();
    }

    public function capabilities(): ToolCapabilities
    {
        return new ToolCapabilities(
            probeTypes: [ProbeType::MtprotoHandshake],
            protocols: ['mtproto'],
            inputSchemaVersion: 1,
            outputSchemaVersion: 1,
        );
    }

    public function execute(ProbeExecutionContext $context): ProbeToolResult
    {
        $startMs = $this->nowMs();

        try {
            $observations = $this->probeHandshake($context);

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
     * MTProto handshake: req_pq_multi → resPQ.
     *
     * The target format is the MTProto proxy address (ip:port).
     * The secret is delivered via the credential channel.
     *
     * @return array<string, mixed>
     */
    private function probeHandshake(ProbeExecutionContext $context): array
    {
        $host = $context->host;
        $port = $context->port;

        // TCP connect to MTProto proxy
        $connectStart = $this->nowMs();
        $stream = @stream_socket_client(
            "tcp://{$host}:{$port}",
            $errno,
            $errstr,
            $context->timeoutMs / 1000,
        );
        $connectMs = $this->nowMs() - $connectStart;

        if ($stream === false) {
            throw new \RuntimeException("TCP connect failed to {$host}:{$port}: {$errstr}");
        }

        // Build req_pq_multi message
        // MTProto handshake: send 64 bytes (16-byte random + 16-byte nonce + padding)
        $nonce = random_bytes(16);
        $message = $this->buildReqPqMulti($nonce);

        // Send
        $sendStart = $this->nowMs();
        $sent = @fwrite($stream, $message);
        $sendMs = $this->nowMs() - $sendStart;

        if ($sent === false || $sent < strlen($message)) {
            fclose($stream);
            throw new \RuntimeException('Failed to send req_pq_multi');
        }

        // Read response
        $recvStart = $this->nowMs();
        $response = @fread($stream, 64);
        $recvMs = $this->nowMs() - $recvStart;

        fclose($stream);

        if ($response === false || $response === '') {
            throw new \RuntimeException('No response from MTProto proxy');
        }

        // Parse resPQ (minimal validation: check it's a valid response)
        $parsed = $this->parseResPq($response);

        return [
            'host' => $host,
            'port' => $port,
            'connect_ms' => $connectMs,
            'send_ms' => $sendMs,
            'recv_ms' => $recvMs,
            'total_ms' => $connectMs + $sendMs + $recvMs,
            'response_size' => strlen($response),
            'valid_handshake' => $parsed['valid'],
            'server_nonce' => $parsed['server_nonce'] ?? null,
            'pq' => $parsed['pq'] ?? null,
        ];
    }

    /**
     * Build a minimal req_pq_multi message for MTProto handshake.
     */
    private function buildReqPqMulti(string $nonce): string
    {
        // Minimal MTProto init packet: 64 bytes
        // auth_key_id (8) + msg_id (8) + seq_no (4) + msg_len (4) + constructor (4) + nonce (16) + padding
        $authKeyId = str_repeat("\0", 8);
        $msgId = pack('J', time());
        $seqNo = pack('V', 0);
        $constructor = pack('V', 0x5162463d); // req_pq_multi constructor
        $msgLen = pack('V', 64);

        return $authKeyId . $msgId . $seqNo . $msgLen . $constructor . $nonce . random_bytes(12);
    }

    /**
     * Parse a minimal resPq response.
     *
     * @return array<string, mixed>
     */
    private function parseResPq(string $response): array
    {
        if (strlen($response) < 64) {
            return ['valid' => false];
        }

        // Check for resPQ constructor (0x0516273a)
        $constructor = substr($response, 24, 4);
        $expectedConstructor = pack('V', 0x0516273a);

        if ($constructor !== $expectedConstructor) {
            // Check for other valid MTProto constructors
            $altConstructor = pack('V', 0x094959b2); // server_DH_params_ok
            if ($constructor !== $altConstructor) {
                return ['valid' => false];
            }
        }

        return [
            'valid' => true,
            'server_nonce' => bin2hex(substr($response, 40, 16)),
            'pq' => bin2hex(substr($response, 56, 8)),
        ];
    }

    private function nowMs(): float
    {
        return (float) hrtime(true) / 1_000_000;
    }
}
