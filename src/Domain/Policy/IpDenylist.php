<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Domain\Policy;

use JsonSerializable;
use RuntimeException;
use ValueError;

/**
 * Denylist of IPv4/IPv6 ranges for SSRF guards: loopback, private, link-local
 * (including the cloud metadata endpoint 169.254.169.254), ULA, multicast,
 * reserved and IPv4-mapped ranges. Pure validation data — no network I/O.
 *
 * The same primitive backs all three connect policies (plan §11.35 п.14); the
 * policies differ in how the denylist is applied, not in the ranges.
 */
final readonly class IpDenylist implements JsonSerializable
{
    public const int SCHEMA_VERSION = 1;

    /**
     * @param  list<string>  $ipv4Ranges  CIDR blocks or single IPs (IPv4).
     * @param  list<string>  $ipv6Ranges  CIDR blocks or single IPs (IPv6).
     */
    public function __construct(
        public readonly array $ipv4Ranges,
        public readonly array $ipv6Ranges,
    ) {}

    /**
     * Default SSRF denylist covering non-routable and sensitive destinations.
     */
    public static function ssrfDefault(): self
    {
        return new self(
            ipv4Ranges: [
                '0.0.0.0/8',
                '10.0.0.0/8',
                '100.64.0.0/10',
                '127.0.0.0/8',
                '169.254.0.0/16',
                '172.16.0.0/12',
                '192.0.0.0/24',
                '192.168.0.0/16',
                '198.18.0.0/15',
                '224.0.0.0/4',
                '240.0.0.0/4',
            ],
            ipv6Ranges: [
                '::/128',
                '::1/128',
                '::ffff:0:0/96',
                '64:ff9b::/96',
                '2001:db8::/32',
                'fc00::/7',
                'fe80::/10',
                'ff00::/8',
            ],
        );
    }

    /**
     * Whether the IP falls into any denied range. IPv4-mapped IPv6 addresses
     * are caught by the ::ffff:0:0/96 range.
     *
     * @throws RuntimeException If the input is not a valid IP literal — callers must fail closed.
     */
    public function contains(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return $this->containsV4($ip);
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return $this->containsV6($ip);
        }

        throw new RuntimeException('IpDenylist received a malformed IP literal.');
    }

    /**
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'ipv4Ranges' => $this->ipv4Ranges,
            'ipv6Ranges' => $this->ipv6Ranges,
            'schemaVersion' => self::SCHEMA_VERSION,
        ];
    }

    /**
     * @param  array<string,mixed>  $data
     *
     * @throws RuntimeException If the format is not recognized.
     */
    public static function fromJson(array $data): self
    {
        return match ($data['schemaVersion'] ?? self::SCHEMA_VERSION) {
            self::SCHEMA_VERSION => self::fromJsonV1($data),
            default => throw new RuntimeException('Unsupported IpDenylist schemaVersion: '.var_export($data['schemaVersion'], true)),
        };
    }

    /**
     * @param  array<string,mixed>  $data
     */
    private static function fromJsonV1(array $data): self
    {
        $ranges = static function (array $raw): array {
            $ranges = array_values(array_map(strval(...), $raw));

            foreach ($ranges as $range) {
                if (! str_contains($range, '/')) {
                    if (@inet_pton($range) === false) {
                        throw new RuntimeException('IpDenylist payload contains an invalid range entry.');
                    }

                    continue;
                }

                [$subnet, $prefix] = explode('/', $range, 2);

                if (@inet_pton($subnet) === false || ! preg_match('/^\d{1,3}$/', $prefix)) {
                    throw new RuntimeException('IpDenylist payload contains an invalid range entry.');
                }
            }

            return $ranges;
        };

        try {
            $list = new self(
                ipv4Ranges: $ranges((array) ($data['ipv4Ranges'] ?? [])),
                ipv6Ranges: $ranges((array) ($data['ipv6Ranges'] ?? [])),
            );
        } catch (ValueError $e) {
            throw new RuntimeException('IpDenylist payload contains an invalid range entry.', previous: $e);
        }

        return $list;
    }

    private function containsV4(string $ip): bool
    {
        $long = (int) (ip2long($ip) ?: 0);

        foreach ($this->ipv4Ranges as $range) {
            [$subnet, $prefix] = $this->splitRange($range);

            if ($prefix > 32) {
                continue;
            }

            $subnetLong = ip2long($subnet);

            if ($subnetLong === false) {
                continue;
            }

            if ($prefix === 0) {
                return true;
            }

            $mask = (-1 << (32 - $prefix)) & 0xFFFFFFFF;

            if ((($long ^ $subnetLong) & $mask) === 0) {
                return true;
            }
        }

        return false;
    }

    private function containsV6(string $ip): bool
    {
        $bytes = (string) inet_pton($ip);

        foreach ($this->ipv6Ranges as $range) {
            [$subnet, $prefix] = $this->splitRange($range);

            if ($prefix > 128) {
                continue;
            }

            $subnetBytes = @inet_pton($subnet);

            if ($subnetBytes === false) {
                continue;
            }

            if (self::prefixMatches($bytes, (string) $subnetBytes, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function splitRange(string $range): array
    {
        if (! str_contains($range, '/')) {
            return [$range, filter_var($range, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ? 32 : 128];
        }

        [$subnet, $prefix] = explode('/', $range, 2);

        return [$subnet, (int) $prefix];
    }

    private static function prefixMatches(string $ipBytes, string $subnetBytes, int $prefix): bool
    {
        $fullBytes = intdiv($prefix, 8);
        $remainderBits = $prefix % 8;

        if (strncmp($ipBytes, $subnetBytes, $fullBytes) !== 0) {
            return false;
        }

        if ($remainderBits === 0) {
            return true;
        }

        $mask = 0xFF << (8 - $remainderBits) & 0xFF;

        return $fullBytes < strlen($ipBytes)
            && ((ord($ipBytes[$fullBytes]) ^ ord($subnetBytes[$fullBytes])) & $mask) === 0;
    }
}
