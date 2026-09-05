<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Transport;

use BAGArt\ProxyOperations\Domain\Identity\ProxyProtocol;
use BAGArt\ProxyOperations\Transport\Adapters\TransportConnectionException;
use BAGArt\ProxyOperations\Transport\Adapters\UdpAssociateProbeContract;

/**
 * Runs capability probes against a proxy endpoint to determine what it supports
 * (plan §11.39 п.16: capability-specific containers; plan §11.4: protocol capability matrix).
 * Results update the endpoint's `proxy_capabilities` table (T06).
 */
final class CapabilityProbeRunner
{
    private const TCP_TEST_HOST = 'httpbin.org';

    private const TCP_TEST_PORT = 80;

    private const DNS_TEST_HOST = 'example.com';

    public function __construct(
        private readonly TransportAdapterResolver $adapterResolver,
        private readonly DnsResolverFactory $dnsResolverFactory,
        private readonly UdpAssociateProbeContract $udpAdapter,
    ) {}

    /**
     * Probe all capabilities for a given proxy config.
     * Returns a CapabilityProbeResult with per-capability findings.
     */
    public function probeAll(ProxyConfig $config): CapabilityProbeResult
    {
        $start = hrtime(true);
        $capabilities = [];

        $tcpResult = $this->probeTcpConnectivity($config);
        $capabilities['tcp'] = $tcpResult->tcpReachable;
        $timingsMs = $tcpResult->timingsMs;

        $dnsMode = null;
        $dnsLeak = null;

        if ($tcpResult->tcpReachable) {
            $udpResult = $this->probeUdpSupport($config);
            $capabilities['udp'] = $udpResult->udpSupported;
            $timingsMs = array_merge($timingsMs, $udpResult->timingsMs);

            $dnsResult = $this->probeDnsCapability($config);
            $dnsMode = $dnsResult->dnsMode;
            $dnsLeak = $dnsResult->dnsLeak;
            if ($dnsMode !== null) {
                $capabilities['dnsMode'] = $dnsMode->value;
            }
            if ($dnsLeak !== null) {
                $capabilities['dnsLeaked'] = $dnsLeak->leaked;
            }
            $timingsMs = array_merge($timingsMs, $dnsResult->timingsMs);
        }

        $timingsMs['totalMs'] = (hrtime(true) - $start) / 1_000_000;

        return new CapabilityProbeResult(
            tcpReachable: $tcpResult->tcpReachable,
            udpSupported: $tcpResult->tcpReachable ? ($capabilities['udp'] ?? null) : null,
            dnsMode: $dnsMode,
            dnsLeak: $dnsLeak,
            capabilities: $capabilities,
            timingsMs: $timingsMs,
        );
    }

    /**
     * Probe TCP connectivity through the proxy (basic reachability).
     */
    public function probeTcpConnectivity(ProxyConfig $config): CapabilityProbeResult
    {
        $start = hrtime(true);

        try {
            $adapter = $this->adapterResolver->resolve($config->scheme);
            $stream = $adapter->connect($config, self::TCP_TEST_HOST, self::TCP_TEST_PORT);
            $elapsedMs = (hrtime(true) - $start) / 1_000_000;

            if (is_resource($stream)) {
                fclose($stream);
            }
            $adapter->close();

            return new CapabilityProbeResult(
                tcpReachable: true,
                udpSupported: null,
                dnsMode: null,
                dnsLeak: null,
                capabilities: ['tcp' => true],
                timingsMs: ['tcpConnect' => $elapsedMs],
            );
        } catch (TransportConnectionException) {
            $elapsedMs = (hrtime(true) - $start) / 1_000_000;

            return CapabilityProbeResult::unreachable(['tcpConnect' => $elapsedMs]);
        }
    }

    /**
     * Probe UDP ASSOCIATE support (SOCKS5/SOCKS5h only).
     */
    public function probeUdpSupport(ProxyConfig $config): CapabilityProbeResult
    {
        $start = hrtime(true);

        if (! in_array($config->scheme, [ProxyProtocol::Socks5, ProxyProtocol::Socks5h], true)) {
            $elapsedMs = (hrtime(true) - $start) / 1_000_000;

            return new CapabilityProbeResult(
                tcpReachable: true,
                udpSupported: false,
                dnsMode: null,
                dnsLeak: null,
                capabilities: ['udp' => false],
                timingsMs: ['udpProbe' => $elapsedMs],
            );
        }

        $result = $this->udpAdapter->associate($config, self::TCP_TEST_HOST, self::TCP_TEST_PORT);
        $elapsedMs = (hrtime(true) - $start) / 1_000_000;

        return new CapabilityProbeResult(
            tcpReachable: true,
            udpSupported: $result->supported,
            dnsMode: null,
            dnsLeak: null,
            capabilities: ['udp' => $result->supported],
            timingsMs: ['udpProbe' => $elapsedMs] + $result->timingsMs,
        );
    }

    /**
     * Probe DNS resolution mode support and detect DNS leaks.
     */
    public function probeDnsCapability(ProxyConfig $config): CapabilityProbeResult
    {
        $start = hrtime(true);

        $resolver = $this->dnsResolverFactory->create($config);
        $dnsProbe = new DnsLeakProbe($resolver);
        $leakResult = $dnsProbe->check(self::DNS_TEST_HOST);
        $elapsedMs = (hrtime(true) - $start) / 1_000_000;

        return new CapabilityProbeResult(
            tcpReachable: true,
            udpSupported: null,
            dnsMode: $leakResult->mode,
            dnsLeak: $leakResult,
            capabilities: ['dnsMode' => $leakResult->mode->value, 'dnsLeaked' => $leakResult->leaked],
            timingsMs: ['dnsProbe' => $elapsedMs] + ['dnsLatency' => $leakResult->latencyMs],
        );
    }
}
