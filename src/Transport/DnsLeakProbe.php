<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Transport;

/**
 * Performs DNS leak detection: resolve a hostname through the proxy's DNS
 * path and compare the resolved IPs against the expected resolver identity.
 * If the resolved IPs don't match what the proxy's resolver would produce,
 * the DNS query leaked to the local network.
 */
final readonly class DnsLeakProbe
{
    public function __construct(
        private readonly DnsResolverContract $resolver,
    ) {
    }

    /**
     * Resolve $hostname and check for DNS leaks.
     *
     * @return DnsLeakResult with resolved IPs and leak assessment.
     */
    public function check(string $hostname): DnsLeakResult
    {
        $start = hrtime(true);

        $resolvedIps = $this->resolver->resolve($hostname);

        $latencyMs = (hrtime(true) - $start) / 1_000_000;

        $mode = $this->resolver->mode();

        $leaked = match ($mode) {
            SocksDnsMode::Local => $this->isLeakedLocal($resolvedIps),
            SocksDnsMode::Remote, SocksDnsMode::ProxyDns => $this->isLeakedProxied($resolvedIps),
        };

        return new DnsLeakResult(
            resolvedIps: $resolvedIps,
            leaked: $leaked,
            mode: $mode,
            latencyMs: $latencyMs,
        );
    }

    /**
     * @param  list<string>  $resolvedIps
     */
    private function isLeakedLocal(array $resolvedIps): bool
    {
        foreach ($resolvedIps as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $resolvedIps
     */
    private function isLeakedProxied(array $resolvedIps): bool
    {
        foreach ($resolvedIps as $ip) {
            if ($ip === '127.0.0.1' || $ip === '::1') {
                return true;
            }
        }

        return false;
    }
}
