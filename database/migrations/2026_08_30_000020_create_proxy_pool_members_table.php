<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Proxy pool members — the materialized membership projection (plan §11.25):
 * NOT a second source of truth. Dynamic rows carry the materialization
 * version that produced them; hand-picked static rows (HYBRID pools) have
 * NULL and survive rebuilds.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proxy_pool_members', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignId('tenant_id')->constrained('users')->cascadeOnDelete();

            $table->uuid('pool_id');
            $table->foreign('pool_id')->references('id')->on('proxy_pools')->cascadeOnDelete();

            $table->uuid('access_id');
            $table->foreign('access_id')->references('id')->on('proxy_accesses')->cascadeOnDelete();

            $table->timestamp('added_at');

            // NULL for hand-picked static members; set by the materializer.
            $table->unsignedBigInteger('materialization_version')->nullable();

            $table->unique(['pool_id', 'access_id']);
            $table->index(['tenant_id', 'access_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proxy_pool_members');
    }
};
