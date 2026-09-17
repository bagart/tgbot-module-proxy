<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Export;

/**
 * Pure helper for tg://proxy URI construction (plan §11.35 п.22).
 * Handles URL encoding, FakeTLS domain hex-encoding, prefix logic.
 */
final class TelegramProxyUriBuilder
{
    /**
     * Build a tg://proxy URI.
     *
     * @param  string  $secret  Hex-encoded secret (may include FakeTLS prefix).
     */
    public static function build(string $host, int $port, string $secret): string
    {
        $encodedHost = self::encodeHost($host);
        $encodedSecret = strtolower(ltrim($secret, '0'));

        return "tg://proxy?server={$encodedHost}&port={$port}&secret={$encodedSecret}";
    }

    /**
     * Build FakeTLS variant: ee{domain_hex}{hex_secret}.
     */
    public static function buildFakeTls(string $host, int $port, string $domain, string $hexSecret): string
    {
        $domainHex = bin2hex($domain);

        return 'tg://proxy?server='.self::encodeHost($host)
            ."&port={$port}&secret=ee{$domainHex}{$hexSecret}";
    }

    /**
     * Build Restricted variant: +r{short_secret}.
     */
    public static function buildRestricted(string $host, int $port, string $shortSecret): string
    {
        return 'tg://proxy?server='.self::encodeHost($host)
            ."&port={$port}&secret=%2Br{$shortSecret}";
    }

    private static function encodeHost(string $host): string
    {
        if (str_contains($host, ':')) {
            return '['.rawurlencode($host).']';
        }

        return rawurlencode($host);
    }
}
