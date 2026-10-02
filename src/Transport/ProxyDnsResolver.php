<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Transport;

use BAGArt\ProxyOperations\Transport\Adapters\Socks5Adapter;
use BAGArt\ProxyOperations\Transport\Adapters\TransportConnectionException;

/**
 * DNS through the proxy (SOCKS5h semantics) — connects to proxy with
 * atyp=0x03 (domain) and target port 53, then sends DNS query over
 * the tunnel. SOCKS5h proxies resolve the domain — the client never
 * sees the IP until the proxy connects.
 */
final class ProxyDnsResolver implements DnsResolverContract
{
    public function __construct(
        private readonly Socks5Adapter $socks5Adapter,
        private readonly ProxyConfig $proxyConfig,
    ) {
    }

    public function resolve(string $hostname): array
    {
        try {
            $stream = $this->socks5Adapter->connect($this->proxyConfig, $hostname, 53);

            $dnsQuery = $this->buildDnsQuery($hostname);

            $bytesWritten = @fwrite($stream, $dnsQuery);

            if ($bytesWritten === false || $bytesWritten === 0) {
                fclose($stream);

                throw new DnsResolutionException(
                    "Failed to send DNS query through proxy for {$hostname}.",
                );
            }

            $response = @fread($stream, 512);

            fclose($stream);

            if ($response === false || $response === '') {
                throw new DnsResolutionException(
                    "No DNS response from proxy for {$hostname}.",
                );
            }

            return $this->parseDnsResponse($response);
        } catch (TransportConnectionException $e) {
            throw new DnsResolutionException(
                "Proxy DNS resolution failed for {$hostname}: {$e->getMessage()}",
                previous: $e,
            );
        }
    }

    public function mode(): SocksDnsMode
    {
        return SocksDnsMode::ProxyDns;
    }

    private function buildDnsQuery(string $hostname): string
    {
        $transactionId = random_bytes(2);
        $flags = pack('n', 0x0100);
        $questions = pack('n', 1);
        $answers = pack('n', 0);
        $authority = pack('n', 0);
        $additional = pack('n', 0);

        $header = $transactionId.$flags.$questions.$answers.$authority.$additional;

        $question = '';
        $parts = explode('.', $hostname);

        foreach ($parts as $part) {
            $question .= chr(strlen($part)).$part;
        }

        $question .= "\x00";
        $question .= pack('nn', 1, 1); // Type A, Class IN

        return $header.$question;
    }

    /**
     * @return list<string>
     */
    private function parseDnsResponse(string $response): array
    {
        if (strlen($response) < 12) {
            throw new DnsResolutionException('DNS response too short.');
        }

        $answerCount = unpack('n', substr($response, 6, 2))[1];
        $ips = [];

        $offset = 12;

        while ($offset < strlen($response) && ord($response[$offset]) !== 0) {
            $labelLen = ord($response[$offset]);
            $offset += 1 + $labelLen;
        }

        $offset += 5; // null byte + type + class

        for ($i = 0; $i < $answerCount && $offset < strlen($response); $i++) {
            $offset += 2; // name (pointer)

            $type = unpack('n', substr($response, $offset, 2))[1];
            $offset += 8; // type + class + ttl

            $rdLength = unpack('n', substr($response, $offset, 2))[1];
            $offset += 2;

            if ($type === 1 && $rdLength === 4) {
                $ips[] = inet_ntop(substr($response, $offset, 4));
            }

            $offset += $rdLength;
        }

        if ($ips === []) {
            throw new DnsResolutionException('No A records in DNS response.');
        }

        return $ips;
    }
}
