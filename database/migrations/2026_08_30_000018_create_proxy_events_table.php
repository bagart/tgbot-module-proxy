<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Proxy events — the event log and the transactional outbox in one table
 * (plan §§11.20, 11.35 пп.18–19, §11.37 R6.7): rows are appended inside the
 * ingestion transaction and dispatched strictly after commit. Payload /
 * identity / occurred_at are immutable; the dispatch metadata
 * (dispatch_status, attempt_count, last_attempt_at, consumed_at) is mutable
 * by the dispatcher only. Dispatched events are never deleted — archival
 * happens via partitions (§11.35 п.19).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proxy_events', function (Blueprint $table): void {
            // ULID/UUID string PK — the event_id of the EventEnvelope.
            $table->uuid('id')->primary();

            $table->foreignId('tenant_id')->constrained('users')->cascadeOnDelete();

            // Models\ProxyEvent dispatch statuses: pending/dispatched/failed.
            $table->string('event_type');
            $table->unsignedInteger('schema_version');
            $table->timestamp('occurred_at');

            $table->string('aggregate_type');
            $table->string('aggregate_id');

            $table->json('payload');

            // Per-tenant monotonic sequence (plan §11.20, T92): ordering key
            // for per-aggregate event consumption.
            $table->unsignedBigInteger('sequence');

            // Dispatcher-owned metadata — the only mutable part of a row (R6.7).
            $table->string('dispatch_status')->default('pending');
            $table->unsignedInteger('attempt_count')->default(0);
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('consumed_at')->nullable();

            $table->unique(['tenant_id', 'sequence']);
            $table->index('dispatch_status');

            // Insert time only; dispatch stamps are explicit columns.
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proxy_events');
    }
};
