<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Verified proxy projection — a read-only view updated by a single projector
 * on AuditCompleted events (plan §11.35 items 4,11). NOT a canonical model;
 * the canonical source is ProxyAccess+ProxyEndpoint+ProxyHealth.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('verified_proxies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('tenant_id')->constrained('users')->cascadeOnDelete();
            $table->uuid('access_id');
            $table->uuid('endpoint_id');
            $table->string('protocol');
            $table->json('credential_projection');
            $table->json('capabilities_projection');
            $table->json('health_projection');
            $table->boolean('telegram_usable')->nullable();
            $table->string('verification_policy_version');
            $table->timestamp('verified_at');
            $table->timestamp('last_checked_at')->nullable();
            $table->unsignedInteger('schema_version')->default(1);
            $table->timestamps();

            $table->unique(['tenant_id', 'access_id']);
            $table->index(['tenant_id', 'verified_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('verified_proxies');
    }
};
