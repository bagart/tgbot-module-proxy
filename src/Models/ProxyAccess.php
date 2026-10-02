<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Models;

use BAGArt\ProxyOperations\Database\Factories\ProxyAccessFactory;
use BAGArt\ProxyOperations\Domain\Failure\FailureCode;
use BAGArt\ProxyOperations\Domain\Identity\EndpointIdentity;
use BAGArt\ProxyOperations\Domain\Lifecycle\AccessState;
use BAGArt\ProxyOperations\Domain\Lifecycle\AccessStateMachine;
use BAGArt\ProxyOperations\Domain\Lifecycle\HealthSignal;
use BAGArt\ProxyOperations\Domain\Lifecycle\LifecycleEvent;
use BAGArt\ProxyOperations\Domain\Lifecycle\QuarantineStatus;
use BAGArt\ProxyOperations\Domain\Lifecycle\TestabilityStatus;
use BAGArt\ProxyOperations\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * The operationally checkable identity: endpoint + credential + ALL lifecycle
 * state (plan §11.35 п.1). INV-001/INV-002: state/testability/quarantine live
 * here, never on ProxyEndpoint. State mutations MUST go through
 * recordTransition() — the single legality gate backed by the canonical
 * AccessStateMachine (plan §11.6, R6.1).
 *
 * @property string $id
 * @property int $tenant_id
 * @property string $endpoint_id
 * @property string|null $credential_id
 * @property string $access_identity_hash
 * @property AccessState $state
 * @property TestabilityStatus $testability_status
 * @property QuarantineStatus $quarantine_status
 * @property string|null $quarantine_reason
 * @property int $consecutive_failures
 * @property int $consecutive_successes
 * @property Carbon|null $last_checked_at
 * @property Carbon|null $state_changed_at
 * @property bool|null $telegram_connectivity
 * @property bool|null $telegram_usable
 * @property Carbon|null $telegram_checked_at
 * @property Carbon|null $telegram_fresh_until
 * @property string|null $telegram_evidence_version
 */
final class ProxyAccess extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'endpoint_id',
        'credential_id',
        'testability_status',
        'quarantine_status',
        'quarantine_reason',
        'consecutive_failures',
        'consecutive_successes',
        'last_checked_at',
        // Factory/seed escape hatch only; domain code mutates the state via
        // recordTransition() so every move is validated and evented.
        'state',
        'telegram_connectivity',
        'telegram_usable',
        'telegram_checked_at',
        'telegram_fresh_until',
        'telegram_evidence_version',
    ];

    /**
     * Mirrors the column defaults so fresh instances carry typed lifecycle
     * values before the first save.
     */
    protected $attributes = [
        'state' => 'new',
        'testability_status' => 'testable',
        'quarantine_status' => 'none',
        'consecutive_failures' => 0,
        'consecutive_successes' => 0,
    ];

    protected static function booted(): void
    {
        self::creating(function (self $access): void {
            if ($access->access_identity_hash === null) {
                $access->forceFill([
                    'access_identity_hash' => self::identityHash($access->endpointIdentity(), $access->credentialFingerprint()),
                ]);
            }
        });
    }

    public function endpointIdentity(): EndpointIdentity
    {
        return $this->endpoint()->firstOrFail()->identity();
    }

    /**
     * Fingerprint of the bound credential profile; null for credential-free
     * accesses (plan §11.2 — probed by bare EndpointIdentity).
     */
    public function credentialFingerprint(): ?string
    {
        $credential = $this->credential_id === null ? null : $this->credential()->first();

        return $credential?->fingerprint;
    }

    /**
     * Tenant-independent hex64 key of the AccessIdentity; uniqueness is scoped
     * by the composite unique index with tenant_id.
     */
    public static function identityHash(EndpointIdentity $endpoint, ?string $credentialFingerprint): string
    {
        return hash('sha256', $endpoint->toString()."\x00".($credentialFingerprint ?? ''));
    }

    /**
     * Applies one lifecycle transition through the canonical AccessStateMachine
     * (legality + cause polarity), stamps state_changed_at and records the
     * emitted LifecycleEvent on the row.
     *
     * §11.35 п.10 gate: WORKING requires a fresh Telegram check — a stale or
     * missing telegram_fresh_until blocks promotion even on a health signal.
     *
     * @throws InvalidArgumentException On an illegal transition, a cause that
     *                                  does not match the rule polarity, or a
     *                                  stale Telegram freshness at Working.
     */
    public function recordTransition(AccessState $to, FailureCode|HealthSignal $cause): LifecycleEvent
    {
        $event = (new AccessStateMachine())->transition($this->state, $to, $cause);

        if ($to === AccessState::Working && $this->telegramUsableNow() !== true) {
            throw new InvalidArgumentException('Transition to working requires a fresh Telegram check (plan §11.35 item 10)');
        }

        $this->forceFill([
            'state' => $to,
            'state_changed_at' => now(),
            'last_transition_event' => $event->jsonSerialize(),
        ])->save();

        return $event;
    }

    /**
     * Last recorded LifecycleEvent, decoded back into its readonly DTO.
     */
    public function lastTransitionEvent(): ?LifecycleEvent
    {
        $payload = $this->last_transition_event;

        return $payload === null ? null : LifecycleEvent::fromJson($payload);
    }

    /**
     * Telegram usability under the freshness policy (§11.35 п.10): null when
     * never checked; true only while usable AND not past telegram_fresh_until.
     */
    public function telegramUsableNow(): ?bool
    {
        if ($this->telegram_checked_at === null) {
            return null;
        }

        if ($this->telegram_usable !== true || $this->telegram_fresh_until === null) {
            return false;
        }

        return $this->telegram_fresh_until->isFuture();
    }

    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(ProxyEndpoint::class);
    }

    public function credential(): BelongsTo
    {
        return $this->belongsTo(ProxyCredential::class);
    }

    protected function casts(): array
    {
        return [
            'state' => AccessState::class,
            'testability_status' => TestabilityStatus::class,
            'quarantine_status' => QuarantineStatus::class,
            'consecutive_failures' => 'integer',
            'consecutive_successes' => 'integer',
            'last_checked_at' => 'datetime',
            'state_changed_at' => 'datetime',
            'telegram_connectivity' => 'boolean',
            'telegram_usable' => 'boolean',
            'telegram_checked_at' => 'datetime',
            'telegram_fresh_until' => 'datetime',
            'last_transition_event' => 'array',
        ];
    }

    protected static function newFactory(): Factory
    {
        return ProxyAccessFactory::new();
    }
}
