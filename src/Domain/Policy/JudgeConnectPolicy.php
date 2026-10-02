<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Domain\Policy;

/**
 * SSRF policy for checker → judge connections (plan §11.35 п.14): strictly
 * allowlist-based. Only the fixed judges of the active JudgeSetSnapshot may be
 * contacted — everything else, however routable, is rejected. Pure policy data.
 */
final readonly class JudgeConnectPolicy
{
    /**
     * @param  list<string>  $judgeUrls  Absolute base URLs of the fixed judge set (scheme + host + optional port + path).
     */
    public function __construct(
        public readonly array $judgeUrls,
    ) {
    }

    /**
     * Fail-closed verdict: the candidate URL must exactly match an allowlisted
     * judge origin (case-insensitive scheme/host, exact port and path).
     */
    public function allowsJudgeUrl(string $candidateUrl): bool
    {
        $candidate = $this->origin($candidateUrl);

        if ($candidate === null) {
            return false;
        }

        foreach ($this->judgeUrls as $judgeUrl) {
            if ($this->origin($judgeUrl) === $candidate) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: string}|null Lowercased [scheme, host, port, path] or null when unparseable.
     */
    private function origin(string $url): ?array
    {
        $parts = parse_url(trim($url));

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        return [
            strtolower((string) $parts['scheme']),
            strtolower((string) $parts['host']),
            (string) ($parts['port'] ?? ''),
            (string) ($parts['path'] ?? ''),
        ];
    }
}
