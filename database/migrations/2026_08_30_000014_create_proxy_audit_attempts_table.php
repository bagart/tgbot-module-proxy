<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Proxy audit attempts (plan §§11.18, 11.19, 11.21): one row per execution
 * try of a job. Worker results report `task_id + attempt_id` with an eternal
 * TTL (§11.19) — the (job_id, attempt_no) unique constraint is the DB-side
 * idempotency gate for those reports.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proxy_audit_attempts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            // Duplicated per §11.22 even though derivable via the job FK.
            $table->foreignId('tenant_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('job_id')->constrained('proxy_audit_jobs')->cascadeOnDelete();

            $table->unsignedInteger('attempt_no');
            $table->string('worker_node')->nullable();

            // Models\AuditAttemptStatus: pending/delivered/running/completed/failed/lost.
            $table->string('status')->default('pending');
            $table->string('result_code')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();

            // Worker-results idempotency (§11.19).
            $table->unique(['job_id', 'attempt_no']);

            $table->index(['tenant_id', 'status']);

            // Placement time only; lifecycle transitions are explicit timestamps.
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proxy_audit_attempts');
    }
};
