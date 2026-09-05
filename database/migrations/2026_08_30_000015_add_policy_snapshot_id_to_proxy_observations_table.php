<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * R6.6 explainability (plan §§11.7, 11.35 п.7): every proxy observation
 * records the policy snapshot the audit ran under, next to the raw evidence.
 * Additive, nullable — pre-T26 rows simply have no snapshot attribution.
 *
 * On SQLite the FK addition forces a full table rebuild, which re-creates the
 * raw descending §11.21 indexes ascending — they are dropped and re-created
 * with their original (identical on SQLite and PostgreSQL) definitions here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proxy_observations', function (Blueprint $table): void {
            $table->foreignUuid('policy_snapshot_id')
                ->nullable()
                ->after('probe_profile_version')
                ->constrained('policy_snapshots')
                ->nullOnDelete();
        });

        DB::statement('drop index if exists proxy_observations_tenant_id_access_id_checked_at_index');
        DB::statement('drop index if exists proxy_observations_access_id_checked_at_index');
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
        Schema::table('proxy_observations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('policy_snapshot_id');
        });
    }
};
