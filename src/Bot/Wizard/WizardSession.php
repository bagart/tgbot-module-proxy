<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Bot\Wizard;

use JsonSerializable;

/**
 * Wizard session state (plan §§10.12 п.11, 11.10).
 * Tracks multi-step bot flows: state transitions, payload, expiry.
 */
final readonly class WizardSession implements JsonSerializable
{
    public const int TTL_MINUTES = 30;

    public function __construct(
        public readonly int $userId,
        public readonly WizardType $type,
        public readonly string $step,
        public readonly array $payload = [],
        public readonly ?string $expiresAt = null,
    ) {
    }

    public function withStep(string $step, array $payloadMerge = []): self
    {
        return new self(
            userId: $this->userId,
            type: $this->type,
            step: $step,
            payload: array_merge($this->payload, $payloadMerge),
            expiresAt: $this->expiresAt,
        );
    }

    public function isExpired(): bool
    {
        if ($this->expiresAt === null) {
            return false;
        }

        return strtotime($this->expiresAt) < time();
    }

    public static function create(int $userId, WizardType $type): self
    {
        return new self(
            userId: $userId,
            type: $type,
            step: 'init',
            expiresAt: date('Y-m-d H:i:s', time() + self::TTL_MINUTES * 60),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'userId' => $this->userId,
            'type' => $this->type->value,
            'step' => $this->step,
            'payload' => $this->payload,
            'expiresAt' => $this->expiresAt,
        ];
    }

    public static function fromJson(array $data): self
    {
        return new self(
            userId: (int) $data['userId'],
            type: WizardType::from($data['type']),
            step: $data['step'],
            payload: $data['payload'] ?? [],
            expiresAt: $data['expiresAt'] ?? null,
        );
    }
}
