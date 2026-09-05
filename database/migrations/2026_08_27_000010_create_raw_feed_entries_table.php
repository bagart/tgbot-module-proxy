<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Import staging table for raw proxy feed entries (plan §§11.21, 11.28,
 * 11.35 п.16–17). Every import source writes raw lines here before the
 * parser processes them. Append-only: no UPDATE, no DELETE after insert.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('raw_feed_entries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('tenant_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('source_id')->nullable()->constrained('proxy_sources')->nullOnDelete();
            $table->uuid('import_batch_id');
            // SHA-256 of normalized input (trim(lower($rawLine)) per §11.19)
            $table->string('batch_hash', 64);
            $table->text('raw_line');
            $table->unsignedInteger('line_number');
            $table->string('status', 20)->default('pending');
            $table->json('parsed_entry_json')->nullable();
            $table->json('parse_error_json')->nullable();
            $table->foreignId('endpoint_id')->nullable()->constrained('proxy_endpoints')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'batch_hash', 'line_number']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'import_batch_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('raw_feed_entries');
    }
};
