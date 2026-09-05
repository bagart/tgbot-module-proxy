<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Proxy audit jobs (plan §§11.18, 11.21): the logical user intent — one row
 * per requested audit run. Execution tries live in proxy_audit_attempts and
 * TaskDelivery identity stays in Redis/T92 keys (Job → Attempt → TaskDelivery
 * split, §11.37 R6.7). Placement idempotency is handled by T24 through the
 * Redis dedup key with TTL window semantics — deliberately no DB unique
 * constraint here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proxy_audit_jobs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('tenant_id')->constrained('users')->cascadeOnDelete();

            // Models\AuditTrigger: manual/scheduled/import/feed/lazy_selection/recovery/tg_check.
            $table->string('trigger');

            $table->foreignUuid('policy_snapshot_id')->constrained('policy_snapshots')->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();

            // Hash of the resolved target set — detects identical placements.
            $table->string('target_set_hash');

            // Models\AuditJobStatus: pending/queued/running/completed/failed/cancelled.
            $table->string('status')->default('pending');
            $table->string('result_code')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'trigger']);
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proxy_audit_jobs');
    }
};
