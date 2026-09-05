<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Wire;

use BAGArt\ProxyOperations\Domain\Identity\AccessIdentity;
use InvalidArgumentException;
use JsonSerializable;
use RuntimeException;

/**
 * Immutable execution snapshot sent Scheduler → Worker (plan §11.35 п.4,
 * §11.39 п.18). The worker receives everything needed to execute without
 * touching Postgres; it is replaceable by a non-PHP implementation (INV-016).
 *
 * `tenantId` is METADATA ONLY — labels for logs/DLQ routing. The worker never
 * performs authorization by tenantId: trust chain ends at the application that
 * created this task (INV-006).
 */
final readonly class AuditTaskV1 implements JsonSerializable
{
    public const int SCHEMA_VERSION = 1;

    public const string CREDENTIAL_MODE_SEALED = 'sealed';

    public const string CREDENTIAL_MODE_REFERENCE = 'reference';

    /**
     * @param  list<ProbeExecutionSpecV1>  $probes
     * @param  string  $deadline  ISO 8601 instant after which the worker must abandon the attempt.
     * @param  string  $accessId  ProxyAccess UUID echoed back in AuditResultV1::accessId; '' for legacy senders.
     */
    public function __construct(
        public readonly JobRef $job,
        public readonly string $tenantId,
        public readonly AccessIdentity $accessRef,
        public readonly ?SealedCredentialPayload $sealedCredential,
        public readonly ?CredentialReference $credentialReference,
        public readonly array $probes,
        public readonly int $policySnapshotVersion,
        public readonly string $deadline,
        public readonly int $maxAttempts,
        public readonly string $accessId = '',
    ) {
        if (($this->sealedCredential === null) === ($this->credentialReference === null)) {
            throw new InvalidArgumentException('AuditTask requires exactly one credential delivery mode: SealedCredentialPayload or CredentialReference.');
        }

        if ($this->deadline === '') {
            throw new InvalidArgumentException('AuditTask deadline must not be empty.');
        }

        foreach ($this->probes as $probe) {
            if (! $probe instanceof ProbeExecutionSpecV1) {
                throw new InvalidArgumentException('AuditTask probes must be ProbeExecutionSpecV1 instances.');
            }
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'job' => $this->job->jsonSerialize(),
            'tenantId' => $this->tenantId,
            'accessRef' => $this->accessRef->jsonSerialize(),
            'credential' => $this->credentialJson(),
            'probes' => array_map(
                static fn (ProbeExecutionSpecV1 $probe): array => $probe->jsonSerialize(),
                $this->probes,
            ),
            'policySnapshotVersion' => $this->policySnapshotVersion,
            'deadline' => $this->deadline,
            'maxAttempts' => $this->maxAttempts,
            'accessId' => $this->accessId,
            'schemaVersion' => self::SCHEMA_VERSION,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function credentialJson(): array
    {
        if ($this->sealedCredential !== null) {
            return ['mode' => self::CREDENTIAL_MODE_SEALED] + $this->sealedCredential->jsonSerialize();
        }

        /** @var CredentialReference $reference */
        $reference = $this->credentialReference;

        return ['mode' => self::CREDENTIAL_MODE_REFERENCE] + $reference->jsonSerialize();
    }

    /**
     * @param  array<string,mixed>  $data
     *
     * @throws RuntimeException If the format is not recognized or the credential mode is invalid.
     */
    public static function fromJson(array $data): self
    {
        return match ($data['schemaVersion'] ?? self::SCHEMA_VERSION) {
            self::SCHEMA_VERSION => self::fromJsonV1($data),
            default => throw new RuntimeException('Unsupported AuditTask schemaVersion: '.var_export($data['schemaVersion'], true)),
        };
    }

    /**
     * @param  array<string,mixed>  $data
     */
    private static function fromJsonV1(array $data): self
    {
        $credential = (array) ($data['credential'] ?? []);
        $mode = (string) ($credential['mode'] ?? '');

        $probes = [];

        foreach ((array) ($data['probes'] ?? []) as $spec) {
            $probes[] = ProbeExecutionSpecV1::fromJson((array) $spec);
        }

        return new self(
            job: JobRef::fromJson((array) ($data['job'] ?? [])),
            tenantId: (string) ($data['tenantId'] ?? ''),
            accessRef: AccessIdentity::fromJson((array) ($data['accessRef'] ?? [])),
            sealedCredential: $mode === self::CREDENTIAL_MODE_SEALED
                ? SealedCredentialPayload::fromJson($credential)
                : null,
            credentialReference: $mode === self::CREDENTIAL_MODE_REFERENCE
                ? CredentialReference::fromJson($credential)
                : null,
            probes: $probes,
            policySnapshotVersion: (int) ($data['policySnapshotVersion'] ?? 0),
            deadline: (string) ($data['deadline'] ?? ''),
            maxAttempts: (int) ($data['maxAttempts'] ?? 0),
            accessId: (string) ($data['accessId'] ?? ''),
        );
    }
}
