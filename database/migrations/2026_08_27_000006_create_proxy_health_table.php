<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Proxy Operations health (plan §§11.7, 11.21, 11.26) — DERIVED table,
 * written only by the future health engine. INV-001: health is ACCESS-scoped,
 * never endpoint-level. IMPROVE#1 (§11.37 R6.x): capability_score is stored
 * strictly separately from health_score. Anonymity tier is a derived
 * interpretation (§11.7) with its classifier version alongside (R6.6).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proxy_health', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            // Duplicated per §11.22 even though derivable via access FK.
            $table->foreignId('tenant_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('access_id')->constrained('proxy_accesses')->cascadeOnDelete();

            $table->smallInteger('health_score')->nullable();
            $table->smallInteger('capability_score')->nullable();

            // [{target, score}] per-target health slices (§11.26).
            $table->json('target_health')->nullable();
            // {p50, p95, p99, jitter_ms} storage reserved for #15.
            $table->json('latency_percentiles')->nullable();

            $table->string('anonymity_tier')->nullable();
            $table->string('anonymity_classifier_version')->nullable();

            $table->string('health_formula_version');

            $table->timestamp('fresh_until')->nullable();
            $table->timestamp('computed_at')->nullable();

            $table->timestamps();

            $table->unique(['tenant_id', 'access_id']);
            $table->index(['tenant_id', 'fresh_until']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proxy_health');
    }
};
