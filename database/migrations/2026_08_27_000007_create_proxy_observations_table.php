<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Proxy Operations observations (plan §11.7, §11.16, §11.21, R6.4): the
 * append-only, immutable raw probe evidence written from Wire\AuditResultV1.
 * TOOL_* / Checker|Platform-class codes never land here (INV-014/015 — enforced
 * again at the model layer). Values are tenant-neutral raw measurements only;
 * the evidence JSON carries the R6.4 allowlist payload (timings, status,
 * content_length, body_hash, bytes_received, exit_ip, dns observations,
 * allowlisted headers) — never credentials/auth headers/request bodies.
 *
 * Plain table now; Postgres declarative partitioning is a dedicated infra
 * migration once the deployment DB lands (README open decision OD-2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proxy_observations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            // Duplicated per §11.22 even though derivable via the access FK.
            $table->foreignId('tenant_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('access_id')->constrained('proxy_accesses')->cascadeOnDelete();

            // Event time of the probe execution — never defaulted to now().
            $table->timestamp('checked_at');

            $table->string('probe_type');
            $table->string('probe_profile');
            $table->string('probe_profile_version');

            // Checker metadata per R6.2.
            $table->string('judge_set_version')->nullable();
            $table->string('checker_node_id')->nullable();
            $table->string('checker_region')->nullable();

            $table->string('outcome'); // success|failure
            $table->string('failure_code')->nullable(); // Domain\Failure\FailureCode
            $table->string('failure_class')->nullable(); // Domain\Failure\FailureClass

            $table->json('evidence');
            $table->unsignedInteger('schema_version')->default(1);

            // Append-only: created_at only, no updated_at.
            $table->timestamp('created_at')->useCurrent();
        });

        // Descending time-ordered indexes (§11.21 shared read paths). Declared
        // raw because Blueprint cannot express per-column sort direction; the
        // syntax below is identical on SQLite (tests) and PostgreSQL.
        DB::statement(
            'create index proxy_observations_tenant_id_access_id_checked_at_index'
            .' on proxy_observations (tenant_id, access_id, checked_at desc)',
        );
        DB::statement(
            'create index proxy_observations_access_id_checked_at_index'
            .' on proxy_observations (access_id, checked_at desc)',
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('proxy_observations');
    }
};
