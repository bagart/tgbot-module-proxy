<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Proxy Operations capabilities (plan §§11.4–11.5, 11.21) — DERIVED table,
 * written only by the future capability evaluator; Stage 1 ships persistence.
 * Endpoint-level part (protocol capability matrix slice) lives on rows with
 * access_id = NULL; access-level part (auth/udp/dns/tg) on rows bound to a
 * ProxyAccess. R6.6: every derived value stores its formula version next to it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proxy_capabilities', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            // Duplicated per §11.22 even though derivable via FKs.
            $table->foreignId('tenant_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('endpoint_id')->constrained('proxy_endpoints')->cascadeOnDelete();
            // NULL = endpoint-level capability row; otherwise access-level part.
            $table->foreignUuid('access_id')->nullable()->constrained('proxy_accesses')->cascadeOnDelete();

            // Access-level capability columns (§11.21).
            $table->boolean('udp_associate_supported')->nullable();
            // LOCAL_DNS|REMOTE_DNS|PROXY_DNS (§11.5).
            $table->string('dns_resolution_mode')->nullable();

            // Protocol capability matrix slice from
            // Domain\Identity\ProtocolCapabilityMatrix / ApplicationCapability.
            $table->json('matrix')->default('[]');

            $table->string('capability_formula_version');
            $table->timestamp('evaluated_at')->nullable();

            $table->timestamps();

            // NULL access_id is distinct per SQLite semantics: several
            // endpoint-level rows may coexist until the evaluator owns this.
            $table->unique(['tenant_id', 'endpoint_id', 'access_id']);
            $table->index(['tenant_id', 'endpoint_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proxy_capabilities');
    }
};
