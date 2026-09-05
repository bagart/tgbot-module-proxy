<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Proxy leases (plan §11.24): lease STATE lives in Postgres (domain truth,
 * survives Redis loss); the Redis lock (T34) is only a concurrency primitive.
 * One ACTIVE lease per AccessIdentity enforced by the (access_id,
 * active_marker) unique index — active_marker is 1 while state=active, NULL
 * otherwise (portable partial-unique emulation for Postgres/SQLite).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proxy_leases', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignId('tenant_id')->constrained('users')->cascadeOnDelete();

            $table->uuid('access_id');
            $table->foreign('access_id')->references('id')->on('proxy_accesses')->cascadeOnDelete();

            $table->string('holder');
            $table->string('purpose')->default('session');

            // active | released | expired | stolen (Domain\Lease\LeaseState).
            $table->string('state')->default('active');

            // 1 while state=active, NULL otherwise — see the unique index.
            $table->unsignedInteger('active_marker')->nullable();

            $table->timestamp('acquired_at');
            $table->timestamp('expires_at');
            $table->timestamp('released_at')->nullable();
            $table->unsignedInteger('renewals')->default(0);

            // Last transition context (pattern of proxy_accesses).
            $table->json('last_lease_event')->nullable();

            $table->timestamps();

            $table->unique(['access_id', 'active_marker']);
            $table->index(['tenant_id', 'holder']);
            $table->index(['tenant_id', 'expires_at']);
            $table->index(['state', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proxy_leases');
    }
};
