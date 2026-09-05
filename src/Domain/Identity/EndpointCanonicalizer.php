<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Domain\Identity;

use InvalidArgumentException;
use RuntimeException;

/**
 * Canonicalizes host/port/protocol into a stable EndpointIdentity: identical
 * endpoints spelled differently must always produce identical identities
 * (dedup, cache and audit addressing rely on this — INV-005/INV-017).
 */
final class EndpointCanonicalizer
{
    public function canonicalize(string $host, int $port, ProxyProtocol $protocol): EndpointIdentity
    {
        return new EndpointIdentity(
            host: $this->canonicalHost(trim($host)),
            port: $this->validatedPort($port),
            protocol: $protocol,
        );
    }

    private function canonicalHost(string $host): string
    {
        if ($host === '') {
            throw new InvalidArgumentException('Proxy host must not be empty.');
        }

        if (str_starts_with($host, '[') || str_contains($host, ':')) {
            return $this->canonicalIpv6(str_starts_with($host, '[') ? trim($host, '[]') : $host);
        }

        $host = rtrim($host, '.');

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return strtolower($host);
        }

        return $this->asciiHostname($host);
    }

    private function canonicalIpv6(string $host): string
    {
        $packed = @inet_pton($host);

        if ($packed === false || strlen($packed) !== 16) {
            throw new InvalidArgumentException("Proxy host is not a valid IPv6 address: {$host}");
        }

        return $this->formatRfc5952($packed);
    }

    private function asciiHostname(string $host): string
    {
        if (! function_exists('idn_to_ascii')) {
            throw new RuntimeException('ext-intl is required to canonicalize non-ASCII hosts.');
        }

        $ascii = idn_to_ascii($host, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);

        if ($ascii === false || preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $ascii) !== 1) {
            throw new InvalidArgumentException("Proxy host is not a valid hostname: {$host}");
        }

        return strtolower($ascii);
    }

    /**
     * RFC 5952: lowercase hex, leading zeros suppressed, longest zero run
     * compressed (leftmost run wins on ties, runs shorter than two groups kept).
     */
    private function formatRfc5952(string $packed): string
    {
        $groups = [];
        foreach (str_split($packed, 2) as $pair) {
            $groups[] = dechex(unpack('n', $pair)[1]);
        }

        $bestStart = -1;
        $bestLength = 0;
        for ($i = 0; $i < 8;) {
            if ($groups[$i] !== '0') {
                $i++;

                continue;
            }
            $j = $i;
            while ($j < 8 && $groups[$j] === '0') {
                $j++;
            }
            if ($j - $i > max($bestLength, 1)) {
                $bestStart = $i;
                $bestLength = $j - $i;
            }
            $i = $j;
        }

        if ($bestStart < 0) {
            return implode(':', $groups);
        }

        return implode(':', array_slice($groups, 0, $bestStart))
            .'::'
            .implode(':', array_slice($groups, $bestStart + $bestLength));
    }

    private function validatedPort(int $port): int
    {
        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException("Proxy port must be in range 1..65535, got {$port}.");
        }

        return $port;
    }
}
