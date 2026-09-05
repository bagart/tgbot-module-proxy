<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Proxy Operations inventory core: proxy_endpoints (plan §11.21) — canonical
 * network identity (scheme/host/port), tenant-scoped. The endpoint never
 * carries health/capability state (INV-002): that belongs to ProxyAccess.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proxy_endpoints', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('tenant_id')->constrained('users')->cascadeOnDelete();
            $table->string('protocol', 20);
            $table->string('host');
            $table->smallInteger('port');
            $table->string('canonical_host');
            // sha256 of the canonical EndpointIdentity, tenant-independent value;
            // uniqueness is tenant-scoped (§11.35 п.2 — no global registry)
            $table->string('endpoint_identity_hash', 64);
            $table->string('comment')->nullable();

            $table->unique(['tenant_id', 'endpoint_identity_hash']);
            $table->index(['tenant_id', 'protocol']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proxy_endpoints');
    }
};
