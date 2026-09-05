<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Proxy pools — tenant-owned pool definitions (plan §§11.21, 11.25): STATIC
 * (hand-picked members), DYNAMIC (predicate-driven projection), HYBRID
 * (predicate + hand-picked). The definition is the truth; members are a
 * projection (T33 materializer).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proxy_pools', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignId('tenant_id')->constrained('users')->cascadeOnDelete();

            $table->string('name');

            // Models have no PoolKind cast need here; the string stays the
            // canonical storage (static|dynamic|hybrid).
            $table->string('kind');

            // Domain\Pool\PoolPredicate JSON; required for dynamic/hybrid,
            // null for static.
            $table->json('predicate')->nullable();

            $table->boolean('enabled')->default(true);
            $table->text('description')->nullable();

            // Lineage of the predicate/policy the pool was defined with.
            $table->unsignedInteger('policy_version')->default(1);

            // Last successful dynamic materialization (T33).
            $table->string('last_materialization_id')->nullable();
            $table->timestamp('last_materialized_at')->nullable();

            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
            $table->index(['tenant_id', 'enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proxy_pools');
    }
};
