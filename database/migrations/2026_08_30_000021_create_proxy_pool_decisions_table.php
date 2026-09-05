<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pool decision log — append-only explainability records (plan §11.25): every
 * candidate decision of a materialization run (accepted / skipped with a
 * reason) answers "why in / why skipped" without re-running the selector.
 * Rows are never updated. Selection runs (T35) reuse this table with
 * materialization_id = selection run id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proxy_pool_decisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignId('tenant_id')->constrained('users')->cascadeOnDelete();

            $table->uuid('pool_id');
            $table->foreign('pool_id')->references('id')->on('proxy_pools')->cascadeOnDelete();

            // Groups all decisions of one materialization (or selection) run.
            $table->string('materialization_id');

            // Null for pool-level decisions (e.g. an aborted run).
            $table->uuid('access_id')->nullable();

            // accepted | skipped (Domain\Pool\SelectionReasonCode).
            $table->string('decision');
            $table->string('reason_code');

            $table->float('score')->nullable();
            $table->unsignedInteger('policy_version');

            // Insert time only — rows are immutable.
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'pool_id', 'materialization_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proxy_pool_decisions');
    }
};
