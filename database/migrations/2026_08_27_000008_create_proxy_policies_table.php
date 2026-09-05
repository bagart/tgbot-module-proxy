<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WorkspacePolicy row (plan §§11.21, 11.29): per-workspace quotas instead of
 * roles (§4), retention flags ("no deletion" §10.11), export rules, UI flags
 * and politeness budget placeholders. Deliberately NOT the job snapshot source:
 * AuditPolicySnapshot stays a separate Stage-0 DTO (plan §11.35 п.7).
 *
 * One policy row per workspace — tenant_id is UNIQUE.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proxy_policies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('tenant_id')->constrained('users')->cascadeOnDelete();
            $table->unique('tenant_id');

            // Bumped on every material change; consumed by a future
            // AuditPolicySnapshot builder to detect stale snapshots.
            $table->string('version', 10)->default('v1');

            $table->json('quotas');
            // #21 budgets placeholder: per-target rate limits, egress class.
            $table->json('politeness');
            $table->json('retention');
            $table->json('export_rules');
            $table->json('ui_flags');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proxy_policies');
    }
};
