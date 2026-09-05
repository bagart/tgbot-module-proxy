<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Proxy Operations accesses (plan §11.21, §§11.2–11.6, §11.35 п.1): the
 * operationally checkable identity (endpoint + optional credential) owning ALL
 * lifecycle state. INV-001/INV-002: state/testability/quarantine live here,
 * never on proxy_endpoints. Telegram freshness fields per §11.35 п.10.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proxy_accesses', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            // Duplicated per §11.22 even though derivable via endpoint FK.
            $table->foreignId('tenant_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('endpoint_id')->constrained('proxy_endpoints')->cascadeOnDelete();
            // NULL = credential-free access (plan §11.2).
            $table->foreignUuid('credential_id')->nullable()->constrained('proxy_credentials')->nullOnDelete();
            // hex64 over the canonical AccessIdentity (endpoint identity +
            // credential fingerprint), tenant-independent value; uniqueness is
            // tenant-scoped by the composite unique index.
            $table->string('access_identity_hash', 64);

            // Canonical lifecycle columns (plan §11.6) — Stage-0 enums.
            $table->string('state')->default('new');
            $table->string('testability_status')->default('testable');
            $table->string('quarantine_status')->default('none');
            $table->string('quarantine_reason')->nullable();
            // Hysteresis input (§11.6), not a second state machine.
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('state_changed_at')->nullable();

            // Telegram freshness block (§11.35 п.10): a stale flag is never
            // issued as usable, even when the last check succeeded.
            $table->boolean('telegram_connectivity')->nullable();
            $table->boolean('telegram_usable')->nullable();
            $table->timestamp('telegram_checked_at')->nullable();
            $table->timestamp('telegram_fresh_until')->nullable();
            $table->string('telegram_evidence_version')->nullable();

            // Last LifecycleEvent emitted by the AccessStateMachine transition
            // (serialized DTO); full append-only history belongs to the
            // proxy_events log/outbox (§11.35 п.19), out of scope here.
            $table->json('last_transition_event')->nullable();

            $table->timestamps();

            $table->unique(['tenant_id', 'access_identity_hash']);
            $table->index(['tenant_id', 'state']);
            $table->index(['endpoint_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proxy_accesses');
    }
};
