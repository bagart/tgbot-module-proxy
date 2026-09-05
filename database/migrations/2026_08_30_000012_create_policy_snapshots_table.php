<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PolicySnapshot rows (plan §§11.18, 11.21, 11.35 п.7): the durable copy of an
 * AuditPolicySnapshot DTO (T93) frozen at job start — probe profile mapping,
 * lifecycle/health thresholds, quarantine rules plus the monotonic
 * policy_version. Postgres is the domain truth (INV-003): a job survives Redis
 * loss because its snapshot lives here. Retention/UI/export flags never enter
 * a snapshot (they belong to WorkspacePolicy).
 *
 * Immutable after create — enforced again at the model layer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('policy_snapshots', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('tenant_id')->constrained('users')->cascadeOnDelete();

            $table->unsignedInteger('policy_version');

            // Serialized AuditPolicySnapshot (JsonSerializable form).
            $table->json('snapshot');

            // Immutable: created_at only, no updated_at.
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'policy_version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_snapshots');
    }
};
