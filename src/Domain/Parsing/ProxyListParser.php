<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Domain\Parsing;

use BAGArt\ProxyOperations\Domain\Identity\CredentialKind;
use BAGArt\ProxyOperations\Domain\Identity\ProxyProtocol;

/**
 * Pure-PHP grammar library that parses proxy list text into structured entries.
 *
 * Single responsibility: text → ParsedEntry[] + ParseError[].
 * No Eloquent, no encryption, no tenant awareness.
 * Canonicalization happens in the application layer (T12), not here.
 */
final class ProxyListParser
{
    private const int DEFAULT_CIDR_MAX_EXPANSION = 1024;

    private const array VPN_SCHEMES = [
        'vless',
        'vmess',
        'trojan',
        'ss',
        'wireguard',
        'openvpn',
    ];

    private const array RECOGNIZED_SCHEMES = [
        'http' => ProxyProtocol::Http,
        'https' => ProxyProtocol::Https,
        'socks4' => ProxyProtocol::Socks4,
        'socks4a' => ProxyProtocol::Socks4a,
        'socks5' => ProxyProtocol::Socks5,
        'socks5h' => ProxyProtocol::Socks5h,
    ];

    private readonly int $cidrMaxExpansion;

    public function __construct(int $cidrMaxExpansion = self::DEFAULT_CIDR_MAX_EXPANSION)
    {
        $this->cidrMaxExpansion = $cidrMaxExpansion;
    }

    public function parse(string $text): ParseResult
    {
        if ($text === '') {
            return new ParseResult(
                entries: [],
                errors: [],
                totalLines: 0,
                parsedCount: 0,
                errorCount: 0,
            );
        }

        $lines = explode("\n", $text);
        $totalLines = count($lines);
        $entries = [];
        $errors = [];

        foreach ($lines as $index => $rawLine) {
            $lineNumber = $index + 1;
            $trimmed = trim($rawLine);

            if ($trimmed === '') {
                $errors[] = new ParseError(
                    line: $lineNumber,
                    rawLine: '',
                    code: ParseErrorCode::EmptyLine,
                    detail: null,
                );

                continue;
            }

            if (str_starts_with($trimmed, '#') || str_starts_with($trimmed, '//')) {
                $errors[] = new ParseError(
                    line: $lineNumber,
                    rawLine: mb_substr($trimmed, 0, 200),
                    code: ParseErrorCode::CommentLine,
                    detail: null,
                );

                continue;
            }

            $result = $this->parseLine($trimmed, $lineNumber);

            if ($result === null) {
                $errors[] = new ParseError(
                    line: $lineNumber,
                    rawLine: mb_substr($trimmed, 0, 200),
                    code: ParseErrorCode::InvalidFormat,
                    detail: "Unable to parse: {$trimmed}",
                );

                continue;
            }

            if ($result instanceof ParseError) {
                $errors[] = $result;

                continue;
            }

            foreach ($result as $entry) {
                $entries[] = $entry;
            }
        }

        return new ParseResult(
            entries: $entries,
            errors: $errors,
            totalLines: $totalLines,
            parsedCount: count($entries),
            errorCount: count($errors),
        );
    }

    /**
     * @return ParsedEntry[]|ParseError|null null when format is unrecognized
     */
    private function parseLine(string $line, int $lineNumber): array|ParseError|null
    {
        $lower = strtolower($line);

        foreach (self::VPN_SCHEMES as $vpnScheme) {
            if (str_starts_with($lower, $vpnScheme.'://')) {
                return new ParseError(
                    line: $lineNumber,
                    rawLine: mb_substr($line, 0, 200),
                    code: ParseErrorCode::NonVpnRejected,
                    detail: "VPN protocol '{$vpnScheme}' is outside the supported scope",
                );
            }
        }

        if (str_starts_with($lower, 'mtproto://')) {
            return $this->parseMtprotoScheme($line, $lineNumber);
        }

        if (str_starts_with($lower, 'tg://proxy?') || str_starts_with($lower, 'https://t.me/proxy?')) {
            return $this->parseMtprotoTgUrl($line, $lineNumber);
        }

        if (preg_match('#^([a-z][a-z0-9+.-]*)://(.*)$#i', $line, $m)) {
            $scheme = strtolower($m[1]);
            $rest = $m[2];

            if (! isset(self::RECOGNIZED_SCHEMES[$scheme])) {
                return new ParseError(
                    line: $lineNumber,
                    rawLine: mb_substr($line, 0, 200),
                    code: ParseErrorCode::UnsupportedScheme,
                    detail: "Scheme '{$scheme}' is not supported",
                );
            }

            if ($rest === '') {
                return new ParseError(
                    line: $lineNumber,
                    rawLine: mb_substr($line, 0, 200),
                    code: ParseErrorCode::InvalidFormat,
                    detail: "Missing host:port after scheme '{$scheme}'",
                );
            }

            return $this->parseSchemeRest(self::RECOGNIZED_SCHEMES[$scheme], $rest, $lineNumber, $line);
        }

        if (str_contains($line, '@')) {
            return $this->parseUserPassAtFormat($line, $lineNumber);
        }

        $colonParts = explode(':', $line);
        if (count($colonParts) === 4) {
            $candidate = $this->tryHostPortUserPass($colonParts, $lineNumber, $line);
            if ($candidate !== null) {
                return $candidate;
            }
        }

        if (preg_match('/^\S+\s+\S+$/', $line)) {
            $spaceParts = preg_split('/\s+/', $line, 2);
            assert($spaceParts !== false);

            return $this->parseHostPortStrict(
                protocol: ProxyProtocol::Socks5,
                host: $spaceParts[0],
                portStr: $spaceParts[1],
                lineNumber: $lineNumber,
                rawLine: $line,
            );
        }

        if (preg_match('/^\[([^\]]+)\]:(\d+)$/', $line, $m)) {
            return $this->parseHostPortStrict(
                protocol: ProxyProtocol::Socks5,
                host: $m[1],
                portStr: $m[2],
                lineNumber: $lineNumber,
                rawLine: $line,
            );
        }

        if (preg_match('/^([^:\s]+):(\d+)$/', $line, $m)) {
            return $this->parseHostPortStrict(
                protocol: ProxyProtocol::Socks5,
                host: $m[1],
                portStr: $m[2],
                lineNumber: $lineNumber,
                rawLine: $line,
            );
        }

        if (preg_match('#^([^/\s]+)/(\d+)$#', $line, $m)) {
            return $this->expandCidr(
                protocol: ProxyProtocol::Socks5,
                baseIp: $m[1],
                prefix: (int) $m[2],
                port: ProxyProtocol::Socks5->defaultPort(),
                lineNumber: $lineNumber,
                rawLine: $line,
                originalHost: $line,
            );
        }

        return null;
    }

    private function parseMtprotoScheme(string $line, int $lineNumber): array|ParseError
    {
        if (! preg_match('/^mtproto:\/\/([^@]+)@(.+)$/i', $line, $m)) {
            return new ParseError(
                line: $lineNumber,
                rawLine: mb_substr($line, 0, 200),
                code: ParseErrorCode::InvalidFormat,
                detail: 'Invalid MTProto URL format',
            );
        }

        $secret = $m[1];
        $hostPort = $m[2];

        $secretError = $this->validateMtprotoSecret($secret, $lineNumber, $line);
        if ($secretError !== null) {
            return $secretError;
        }

        $parsed = $this->splitHostPort($hostPort);
        if ($parsed === null) {
            return $this->makeHostPortError($hostPort, $lineNumber, $line);
        }

        [$host, $port] = $parsed;

        return [$this->makeMtprotoEntry($host, $port, $secret, $lineNumber, $host)];
    }

    private function parseMtprotoTgUrl(string $line, int $lineNumber): array|ParseError
    {
        $queryStart = strpos($line, '?');
        if ($queryStart === false) {
            return new ParseError(
                line: $lineNumber,
                rawLine: mb_substr($line, 0, 200),
                code: ParseErrorCode::InvalidFormat,
                detail: 'Missing query parameters in tg://proxy URL',
            );
        }

        $queryString = substr($line, $queryStart + 1);
        $params = [];
        parse_str($queryString, $params);

        $server = $params['server'] ?? null;
        $portStr = $params['port'] ?? null;
        $secret = $params['secret'] ?? null;

        if ($server === null || $portStr === null || $secret === null) {
            return new ParseError(
                line: $lineNumber,
                rawLine: mb_substr($line, 0, 200),
                code: ParseErrorCode::InvalidFormat,
                detail: 'Missing required query parameters (server, port, secret)',
            );
        }

        $server = urldecode($server);
        $portStr = urldecode($portStr);
        $secret = urldecode($secret);

        $secretError = $this->validateMtprotoSecret($secret, $lineNumber, $line);
        if ($secretError !== null) {
            return $secretError;
        }

        $host = $this->unwrapIpv6($server);
        if ($host === null) {
            return new ParseError(
                line: $lineNumber,
                rawLine: mb_substr($line, 0, 200),
                code: ParseErrorCode::InvalidHost,
                detail: "Invalid host: {$server}",
            );
        }

        $port = $this->validatePort($portStr);
        if (is_string($port)) {
            return new ParseError(
                line: $lineNumber,
                rawLine: mb_substr($line, 0, 200),
                code: $this->isPortRangeError($portStr) ? ParseErrorCode::InvalidPortRange : ParseErrorCode::InvalidPort,
                detail: $port,
            );
        }

        return [$this->makeMtprotoEntry($host, $port, $secret, $lineNumber, $host)];
    }

    private function parseSchemeRest(ProxyProtocol $protocol, string $rest, int $lineNumber, string $rawLine): array|ParseError
    {
        $atPos = strrpos($rest, '@');
        if ($atPos !== false) {
            $credentials = substr($rest, 0, $atPos);
            $hostPort = substr($rest, $atPos + 1);

            $credParts = explode(':', $credentials);
            if (count($credParts) !== 2 || $credParts[0] === '' || $credParts[1] === '') {
                return new ParseError(
                    line: $lineNumber,
                    rawLine: mb_substr($rawLine, 0, 200),
                    code: ParseErrorCode::InvalidFormat,
                    detail: 'Invalid credentials format, expected username:password',
                );
            }

            return $this->parseSchemeHostPortWithCreds($protocol, $hostPort, $credParts[0], $credParts[1], $lineNumber, $rawLine);
        }

        return $this->parseHostPortOrCidr($protocol, $rest, $lineNumber, $rawLine);
    }

    /**
     * @return ParsedEntry[]|ParseError
     */
    private function parseSchemeHostPortWithCreds(ProxyProtocol $protocol, string $hostPort, string $username, string $password, int $lineNumber, string $rawLine): array|ParseError
    {
        $parsed = $this->splitHostPort($hostPort);
        if ($parsed === null) {
            return $this->makeHostPortError($hostPort, $lineNumber, $rawLine);
        }

        [$host, $port] = $parsed;

        return $this->makeEntryWithCreds($protocol, $host, $port, $username, $password, $lineNumber, $host);
    }

    private function parseUserPassAtFormat(string $line, int $lineNumber): array|ParseError
    {
        $atPos = strrpos($line, '@');
        assert($atPos !== false);

        $credentials = substr($line, 0, $atPos);
        $hostPort = substr($line, $atPos + 1);

        $credParts = explode(':', $credentials);
        if (count($credParts) !== 2 || $credParts[0] === '' || $credParts[1] === '') {
            return new ParseError(
                line: $lineNumber,
                rawLine: mb_substr($line, 0, 200),
                code: ParseErrorCode::InvalidFormat,
                detail: 'Invalid credentials format, expected username:password',
            );
        }

        $parsed = $this->splitHostPort($hostPort);
        if ($parsed === null) {
            return $this->makeHostPortError($hostPort, $lineNumber, $line);
        }

        [$host, $port] = $parsed;

        return $this->makeEntryWithCreds(ProxyProtocol::Socks5, $host, $port, $credParts[0], $credParts[1], $lineNumber, $host);
    }

    /**
     * @return ParsedEntry[]|ParseError|null
     */
    private function tryHostPortUserPass(array $parts, int $lineNumber, string $rawLine): array|ParseError|null
    {
        [$hostStr, $portStr, $user, $pass] = $parts;

        $port = $this->validatePort($portStr);
        if (is_string($port)) {
            return null;
        }

        $host = $this->unwrapIpv6($hostStr);
        if ($host === null) {
            return null;
        }

        if ($user === '' || $pass === '') {
            return new ParseError(
                line: $lineNumber,
                rawLine: mb_substr($rawLine, 0, 200),
                code: ParseErrorCode::InvalidFormat,
                detail: 'Empty username or password in host:port:username:password format',
            );
        }

        return $this->makeEntryWithCreds(ProxyProtocol::Socks5, $host, $port, $user, $pass, $lineNumber, $host);
    }

    /**
     * @return ParsedEntry[]|ParseError
     */
    private function parseHostPortOrCidr(ProxyProtocol $protocol, string $rest, int $lineNumber, string $rawLine): array|ParseError
    {
        if (preg_match('#^([^/]+)/(\d+)(?::(\d+))?$#', $rest, $m)) {
            $cidrBase = $m[1];
            $cidrPrefix = (int) $m[2];
            $port = $m[3] ?? null;

            $actualPort = $port !== null
                ? $this->validatePort($port)
                : $protocol->defaultPort();

            if (is_string($actualPort)) {
                return new ParseError(
                    line: $lineNumber,
                    rawLine: mb_substr($rawLine, 0, 200),
                    code: $this->isPortRangeError($port ?? '') ? ParseErrorCode::InvalidPortRange : ParseErrorCode::InvalidPort,
                    detail: $actualPort,
                );
            }

            return $this->expandCidr(
                protocol: $protocol,
                baseIp: $cidrBase,
                prefix: $cidrPrefix,
                port: $actualPort,
                lineNumber: $lineNumber,
                rawLine: $rawLine,
                originalHost: "{$cidrBase}/{$cidrPrefix}",
            );
        }

        $lastColon = strrpos($rest, ':');
        if ($lastColon === false) {
            return $this->makeHostPortError($rest, $lineNumber, $rawLine);
        }

        $host = substr($rest, 0, $lastColon);
        $portStr = substr($rest, $lastColon + 1);

        if ($host === '') {
            return $this->makeHostPortError($rest, $lineNumber, $rawLine);
        }

        $port = $this->validatePort($portStr);
        if (is_string($port)) {
            return new ParseError(
                line: $lineNumber,
                rawLine: mb_substr($rawLine, 0, 200),
                code: $this->isPortRangeError($portStr) ? ParseErrorCode::InvalidPortRange : ParseErrorCode::InvalidPort,
                detail: $port,
            );
        }

        $cleanHost = $this->unwrapIpv6($host);
        if ($cleanHost === null || $cleanHost === '') {
            return new ParseError(
                line: $lineNumber,
                rawLine: mb_substr($rawLine, 0, 200),
                code: ParseErrorCode::InvalidHost,
                detail: "Invalid host: {$host}",
            );
        }

        return $this->makeEntry($protocol, $cleanHost, $port, null, null, $lineNumber, $cleanHost);
    }

    /**
     * @return ParsedEntry[]|ParseError
     */
    private function parseHostPortStrict(ProxyProtocol $protocol, string $host, string $portStr, int $lineNumber, string $rawLine): array|ParseError
    {
        $cleanHost = $this->unwrapIpv6($host);
        if ($cleanHost === null || $cleanHost === '') {
            return new ParseError(
                line: $lineNumber,
                rawLine: mb_substr($rawLine, 0, 200),
                code: ParseErrorCode::InvalidHost,
                detail: "Invalid host: {$host}",
            );
        }

        $port = $this->validatePort($portStr);
        if (is_string($port)) {
            return new ParseError(
                line: $lineNumber,
                rawLine: mb_substr($rawLine, 0, 200),
                code: $this->isPortRangeError($portStr) ? ParseErrorCode::InvalidPortRange : ParseErrorCode::InvalidPort,
                detail: $port,
            );
        }

        return $this->makeEntry($protocol, $cleanHost, $port, null, null, $lineNumber, $cleanHost);
    }

    /**
     * @return ParsedEntry[]|ParseError
     */
    private function expandCidr(ProxyProtocol $protocol, string $baseIp, int $prefix, int $port, int $lineNumber, string $rawLine, string $originalHost): array|ParseError
    {
        if ($prefix < 0 || $prefix > 32) {
            return new ParseError(
                line: $lineNumber,
                rawLine: mb_substr($rawLine, 0, 200),
                code: ParseErrorCode::InvalidFormat,
                detail: "Invalid CIDR prefix: {$prefix}",
            );
        }

        $baseAddr = @inet_pton($baseIp);
        if ($baseAddr === false) {
            return new ParseError(
                line: $lineNumber,
                rawLine: mb_substr($rawLine, 0, 200),
                code: ParseErrorCode::InvalidHost,
                detail: "Invalid IP address in CIDR: {$baseIp}",
            );
        }

        $total = 1 << (32 - $prefix);
        if ($total > $this->cidrMaxExpansion) {
            return new ParseError(
                line: $lineNumber,
                rawLine: mb_substr($rawLine, 0, 200),
                code: ParseErrorCode::CidrExpansionFailed,
                detail: "CIDR {$baseIp}/{$prefix} would expand to {$total} addresses, exceeding limit of {$this->cidrMaxExpansion}",
            );
        }

        $baseInt = unpack('N', substr($baseAddr, 0, 4))[1];
        $mask = $prefix === 0 ? 0 : (~0 << (32 - $prefix));
        $networkBase = $baseInt & $mask;

        $entries = [];
        for ($i = 0; $i < $total; $i++) {
            $ip = long2ip($networkBase + $i);

            $entries[] = new ParsedEntry(
                scheme: $protocol->value,
                host: $ip,
                port: $port,
                username: null,
                secret: null,
                credentialKind: CredentialKind::SocksAuth,
                sourceLine: $lineNumber,
                originalHost: $originalHost,
            );
        }

        return $entries;
    }

    private function validateMtprotoSecret(string $secret, int $lineNumber, string $rawLine): ?ParseError
    {
        $checkSecret = $secret;
        if (str_starts_with($secret, '+r')) {
            $checkSecret = substr($secret, 2);
        }

        if (! ctype_xdigit($checkSecret)) {
            return new ParseError(
                line: $lineNumber,
                rawLine: mb_substr($rawLine, 0, 200),
                code: ParseErrorCode::InvalidMtprotoSecret,
                detail: 'MTProto secret contains non-hex characters',
            );
        }

        $hexLen = strlen($checkSecret);
        if ($hexLen < 32) {
            return new ParseError(
                line: $lineNumber,
                rawLine: mb_substr($rawLine, 0, 200),
                code: ParseErrorCode::InvalidMtprotoSecret,
                detail: "MTProto secret too short: {$hexLen} hex chars, minimum is 32",
            );
        }

        return null;
    }

    /**
     * Split HOST:PORT from a string, handling IPv6 in brackets.
     *
     * @return array{0: string, 1: int}|null null when splitting fails
     */
    private function splitHostPort(string $hostPort): ?array
    {
        if (preg_match('/^\[([^\]]+)\]:(\d+)$/', $hostPort, $m)) {
            $host = $m[1];
            $port = $this->validatePort($m[2]);
            if (is_string($port)) {
                return null;
            }

            return [$host, $port];
        }

        $lastColon = strrpos($hostPort, ':');
        if ($lastColon === false) {
            return null;
        }

        $host = substr($hostPort, 0, $lastColon);
        $portStr = substr($hostPort, $lastColon + 1);

        if ($host === '') {
            return null;
        }

        $port = $this->validatePort($portStr);
        if (is_string($port)) {
            return null;
        }

        return [$host, $port];
    }

    private function unwrapIpv6(string $host): ?string
    {
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            return substr($host, 1, -1);
        }

        return $host;
    }

    private function validatePort(string $portStr): int|string
    {
        if (! preg_match('/^-?\d+$/', $portStr)) {
            return "Port is not a valid integer: {$portStr}";
        }

        $port = (int) $portStr;

        if ($port < 1 || $port > 65535) {
            return "Port out of range 1..65535: {$port}";
        }

        return $port;
    }

    private function isPortRangeError(string $portStr): bool
    {
        return preg_match('/^-?\d+$/', $portStr) === 1;
    }

    private function makeMtprotoEntry(string $host, int $port, string $secret, int $lineNumber, string $originalHost): ParsedEntry
    {
        return new ParsedEntry(
            scheme: ProxyProtocol::Mtproto->value,
            host: $host,
            port: $port,
            username: null,
            secret: $secret,
            credentialKind: CredentialKind::MtprotoSecret,
            sourceLine: $lineNumber,
            originalHost: $originalHost,
        );
    }

    private function makeEntry(ProxyProtocol $protocol, string $host, int $port, ?string $username, ?string $password, int $lineNumber, string $originalHost): array
    {
        $hasCredentials = $username !== null && $password !== null;

        $credentialKind = match (true) {
            $hasCredentials && in_array($protocol, [ProxyProtocol::Http, ProxyProtocol::Https], true) => CredentialKind::BasicAuth,
            $hasCredentials => CredentialKind::SocksAuth,
            default => CredentialKind::SocksAuth,
        };

        return [
            new ParsedEntry(
                scheme: $protocol->value,
                host: $host,
                port: $port,
                username: $username,
                secret: $password,
                credentialKind: $credentialKind,
                sourceLine: $lineNumber,
                originalHost: $originalHost,
            ),
        ];
    }

    private function makeEntryWithCreds(ProxyProtocol $protocol, string $host, int $port, string $username, string $password, int $lineNumber, string $originalHost): array
    {
        return $this->makeEntry($protocol, $host, $port, $username, $password, $lineNumber, $originalHost);
    }

    private function makeHostPortError(string $hostPort, int $lineNumber, string $rawLine): ParseError
    {
        return new ParseError(
            line: $lineNumber,
            rawLine: mb_substr($rawLine, 0, 200),
            code: ParseErrorCode::InvalidFormat,
            detail: "Invalid host:port format: {$hostPort}",
        );
    }
}
