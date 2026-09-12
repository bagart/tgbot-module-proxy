<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Tool;

use BAGArt\ProxyOperations\Domain\Failure\ExecutionFailure;
use BAGArt\ProxyOperations\Domain\Failure\FailureCode;
use BAGArt\ProxyOperations\Domain\Failure\FailureTaxonomy;
use BAGArt\ProxyOperations\Domain\Probe\ProbeType;
use BAGArt\ProxyOperations\Transport\ProxyConfig;
use BAGArt\ProxyOperations\Transport\ProxyConfigFactory;
use Throwable;

/**
 * HTTP-based probe tool: handles HttpLiveness, LatencySeries, HeaderMarker,
 * and AnonymityHeaders probe types via cURL through the proxy under test.
 *
 * The tool builds a cURL handle that connects through the proxy to the
 * target URL, captures timing observations and response headers, and
 * returns raw observations for the evidence pipeline.
 */
final class HttpProbeTool implements ProbeTool
{
    private readonly FailureTaxonomy $taxonomy;

    public function __construct()
    {
        $this->taxonomy = new FailureTaxonomy;
    }

    public function capabilities(): ToolCapabilities
    {
        return new ToolCapabilities(
            probeTypes: [
                ProbeType::HttpLiveness,
                ProbeType::LatencySeries,
                ProbeType::HeaderMarker,
                ProbeType::AnonymityHeaders,
            ],
            protocols: ['http', 'https', 'socks5', 'socks5h'],
            inputSchemaVersion: 1,
            outputSchemaVersion: 1,
        );
    }

    public function execute(ProbeExecutionContext $context): ProbeToolResult
    {
        $startMs = $this->nowMs();

        try {
            $observations = match ($context->spec->probeType) {
                ProbeType::HttpLiveness => $this->probeLiveness($context),
                ProbeType::LatencySeries => $this->probeLatency($context),
                ProbeType::HeaderMarker => $this->probeHeaders($context),
                ProbeType::AnonymityHeaders => $this->probeAnonymity($context),
                default => throw new \InvalidArgumentException("HttpProbeTool does not support {$context->spec->probeType->value}"),
            };

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
     * HTTP liveness: full body fetch through proxy, measures TTFB + total time.
     *
     * @return array<string, mixed>
     */
    private function probeLiveness(ProbeExecutionContext $context): array
    {
        $ch = $this->buildCurlHandle($context);
        curl_setopt_array($ch, [
            CURLOPT_URL => $context->spec->target,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_NOBODY => false,
        ]);

        $body = curl_exec($ch);
        $info = curl_getinfo($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($errno !== 0) {
            throw new \RuntimeException("cURL error {$errno}: {$error}");
        }

        $statusCode = (int) ($info['http_code'] ?? 0);
        $bodySize = is_string($body) ? strlen($body) : 0;

        return [
            'status_code' => $statusCode,
            'body_bytes' => $bodySize,
            'ttfb_ms' => (float) ($info['starttransfer_time'] ?? 0) * 1000,
            'total_time_ms' => (float) ($info['total_time'] ?? 0) * 1000,
            'redirect_count' => (int) ($info['num_redirects'] ?? 0),
            'ssl_verify_result' => (int) ($info['ssl_verify_result'] ?? -1),
            'live' => $statusCode >= 200 && $statusCode < 400,
        ];
    }

    /**
     * Latency series: N sequential requests to measure p50/p95/p99 + jitter.
     *
     * @return array<string, mixed>
     */
    private function probeLatency(ProbeExecutionContext $context): array
    {
        $samples = [];
        $seriesCount = 5;

        for ($i = 0; $i < $seriesCount; $i++) {
            $ch = $this->buildCurlHandle($context);
            curl_setopt_array($ch, [
                CURLOPT_URL => $context->spec->target,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER => false,
                CURLOPT_NOBODY => true,
            ]);

            curl_exec($ch);
            $info = curl_getinfo($ch);
            $errno = curl_errno($ch);
            curl_close($ch);

            if ($errno !== 0) {
                $samples[] = -1.0;
                continue;
            }

            $samples[] = (float) ($info['total_time'] ?? 0) * 1000;
        }

        $validSamples = array_filter($samples, fn (float $s) => $s >= 0);
        sort($validSamples, SORT_NUMERIC);
        $count = count($validSamples);

        if ($count === 0) {
            throw new \RuntimeException('All latency samples failed');
        }

        return [
            'samples_ms' => $validSamples,
            'p50_ms' => $this->percentile($validSamples, 50),
            'p95_ms' => $this->percentile($validSamples, 95),
            'p99_ms' => $this->percentile($validSamples, 99),
            'jitter_ms' => $this->jitter($validSamples),
            'min_ms' => min($validSamples),
            'max_ms' => max($validSamples),
            'sample_count' => $count,
        ];
    }

    /**
     * Header marker: fetch response headers for integrity/cache detection.
     *
     * @return array<string, mixed>
     */
    private function probeHeaders(ProbeExecutionContext $context): array
    {
        $ch = $this->buildCurlHandle($context);
        curl_setopt_array($ch, [
            CURLOPT_URL => $context->spec->target,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_NOBODY => false,
        ]);

        $response = curl_exec($ch);
        $info = curl_getinfo($ch);
        $errno = curl_errno($ch);
        curl_close($ch);

        if ($errno !== 0) {
            throw new \RuntimeException("cURL error {$errno}");
        }

        $headerSize = (int) ($info['header_size'] ?? 0);
        $rawHeaders = is_string($response) ? substr($response, 0, $headerSize) : '';
        $headers = $this->parseHeaders($rawHeaders);

        return [
            'status_code' => (int) ($info['http_code'] ?? 0),
            'headers' => $headers,
            'content_type' => $info['content_type'] ?? null,
            'server' => $headers['server'] ?? null,
            'cache_control' => $headers['cache-control'] ?? null,
        ];
    }

    /**
     * Anonymity headers: detect proxy-identifying headers (Via, X-Forwarded-For, etc.).
     *
     * @return array<string, mixed>
     */
    private function probeAnonymity(ProbeExecutionContext $context): array
    {
        $base = $this->probeHeaders($context);

        $leakHeaders = ['via', 'x-forwarded-for', 'x-forwarded-proto', 'x-forwarded-host',
            'x-real-ip', 'forwarded', 'proxy-connection', 'x-proxy-user', 'x-proxy-user-agent'];

        $leaked = [];
        foreach ($leakHeaders as $header) {
            if (isset($base['headers'][$header])) {
                $leaked[$header] = $base['headers'][$header];
            }
        }

        return [
            ...$base,
            'anonymity_leak' => $leaked,
            'is_anonymous' => $leaked === [],
        ];
    }

    private function buildCurlHandle(ProbeExecutionContext $context): \CurlHandle
    {
        $ch = curl_init();

        $timeoutSec = max(1, (int) ceil($context->timeoutMs / 1000));

        curl_setopt_array($ch, [
            CURLOPT_CONNECTTIMEOUT_MS => $context->timeoutMs,
            CURLOPT_TIMEOUT_MS => $context->timeoutMs,
            CURLOPT_DNS_CACHE_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_VERBOSE => false,
        ]);

        // Route through proxy if the context implies a proxy endpoint
        // The host/port in context are the PROXY's host/port, not the target's
        if ($context->host !== '' && $context->port > 0) {
            curl_setopt($ch, CURLOPT_PROXY, "{$context->host}:{$context->port}");
            curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP);
        }

        return $ch;
    }

    /**
     * @param  list<float>  $samples
     */
    private function percentile(array $samples, int $p): float
    {
        $count = count($samples);
        if ($count === 0) {
            return 0.0;
        }
        $index = (int) ceil(($p / 100) * $count) - 1;

        return $samples[max(0, min($index, $count - 1))];
    }

    /**
     * @param  list<float>  $samples
     */
    private function jitter(array $samples): float
    {
        if (count($samples) < 2) {
            return 0.0;
        }
        $diffs = [];
        for ($i = 1; $i < count($samples); $i++) {
            $diffs[] = abs($samples[$i] - $samples[$i - 1]);
        }

        return array_sum($diffs) / count($diffs);
    }

    /**
     * @param  string  $rawHeaders
     * @return array<string, string>
     */
    private function parseHeaders(string $rawHeaders): array
    {
        $headers = [];
        $lines = explode("\r\n", $rawHeaders);
        foreach ($lines as $line) {
            if (str_contains($line, ':')) {
                [$key, $value] = explode(':', $line, 2);
                $headers[strtolower(trim($key))] = trim($value);
            }
        }

        return $headers;
    }

    private function nowMs(): float
    {
        return (float) hrtime(true) / 1_000_000;
    }
}
