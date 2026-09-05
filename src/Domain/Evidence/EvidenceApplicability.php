<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Domain\Evidence;

use BAGArt\ProxyOperations\Domain\Identity\ProxyProtocol;

/**
 * Which evidence applies to which protocol (plan §11.35 item 9, R6.3):
 * every dimension is REQUIRED / OPTIONAL / NOT_APPLICABLE per access type.
 *
 * Invariants enforced by tests: no protocol without required evidence; no
 * evidence type without at least one protocol that consumes it; MTProto has
 * HTTP/UDP/DNS/Judge probes N/A so they never block a WORKING relay.
 */
final class EvidenceApplicability
{
    /**
     * Protocol name → evidence type name → applicability.
     *
     * @var array<non-empty-string, array<non-empty-string, Applicability>>
     */
    private const array MATRIX = [
        'Http' => [
            'tcp' => Applicability::Required,
            'http' => Applicability::Required,
            'tls' => Applicability::Optional,
            'dns' => Applicability::NotApplicable,
            'udp' => Applicability::NotApplicable,
            'judge' => Applicability::Required,
            'telegram' => Applicability::Optional,
            'bandwidth' => Applicability::Optional,
        ],
        'Https' => [
            'tcp' => Applicability::Required,
            'http' => Applicability::Required,
            'tls' => Applicability::Optional,
            'dns' => Applicability::NotApplicable,
            'udp' => Applicability::NotApplicable,
            'judge' => Applicability::Required,
            'telegram' => Applicability::Optional,
            'bandwidth' => Applicability::Optional,
        ],
        'Socks4' => [
            'tcp' => Applicability::Required,
            'http' => Applicability::Required,
            'tls' => Applicability::Optional,
            'dns' => Applicability::NotApplicable,
            'udp' => Applicability::NotApplicable,
            'judge' => Applicability::Required,
            'telegram' => Applicability::Optional,
            'bandwidth' => Applicability::Optional,
        ],
        'Socks4a' => [
            'tcp' => Applicability::Required,
            'http' => Applicability::Required,
            'tls' => Applicability::Optional,
            'dns' => Applicability::NotApplicable,
            'udp' => Applicability::NotApplicable,
            'judge' => Applicability::Required,
            'telegram' => Applicability::Optional,
            'bandwidth' => Applicability::Optional,
        ],
        'Socks5' => [
            'tcp' => Applicability::Required,
            'http' => Applicability::Required,
            'tls' => Applicability::Optional,
            'dns' => Applicability::Optional,
            'udp' => Applicability::Optional,
            'judge' => Applicability::Required,
            'telegram' => Applicability::Optional,
            'bandwidth' => Applicability::Optional,
        ],
        'Socks5h' => [
            'tcp' => Applicability::Required,
            'http' => Applicability::Required,
            'tls' => Applicability::Optional,
            'dns' => Applicability::Optional,
            'udp' => Applicability::Optional,
            'judge' => Applicability::Required,
            'telegram' => Applicability::Optional,
            'bandwidth' => Applicability::Optional,
        ],
        'Mtproto' => [
            'tcp' => Applicability::Required,
            'http' => Applicability::NotApplicable,
            'tls' => Applicability::NotApplicable,
            'dns' => Applicability::NotApplicable,
            'udp' => Applicability::NotApplicable,
            'judge' => Applicability::NotApplicable,
            'telegram' => Applicability::Required,
            'bandwidth' => Applicability::Optional,
        ],
    ];

    public function for(ProxyProtocol $protocol, EvidenceType $type): Applicability
    {
        return self::MATRIX[$protocol->name][$type->value];
    }

    /**
     * Evidence types with a real consumer for this protocol
     * (everything except NotApplicable).
     *
     * @return list<EvidenceType>
     */
    public function applicableFor(ProxyProtocol $protocol): array
    {
        return $this->filter($protocol, fn (Applicability $a): bool => $a !== Applicability::NotApplicable);
    }

    /**
     * Evidence a protocol must present before any positive classification.
     *
     * @return list<EvidenceType>
     */
    public function requiredFor(ProxyProtocol $protocol): array
    {
        return $this->filter($protocol, fn (Applicability $a): bool => $a === Applicability::Required);
    }

    /**
     * @param  callable(Applicability): bool  $predicate
     * @return list<EvidenceType>
     */
    private function filter(ProxyProtocol $protocol, callable $predicate): array
    {
        $types = [];

        foreach (self::MATRIX[$protocol->name] as $typeName => $applicability) {
            if ($predicate($applicability)) {
                $types[] = EvidenceType::from($typeName);
            }
        }

        return $types;
    }
}
