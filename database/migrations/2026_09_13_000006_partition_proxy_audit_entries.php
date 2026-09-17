<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Partition proxy_audit_entries by month for high-volume data (plan §11.10 п.4).
 * Partition pruning keeps queries fast as audit data grows.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->shouldPartition()) {
            return;
        }

        DB::statement('
            CREATE TABLE IF NOT EXISTS proxy_audit_entries_partitioned (
                id BIGSERIAL,
                access_id VARCHAR(64) NOT NULL,
                endpoint VARCHAR(512) NOT NULL,
                score NUMERIC(5,4),
                status VARCHAR(32) NOT NULL,
                judge_id VARCHAR(64),
                latency_ms INTEGER,
                observed_at TIMESTAMP NOT NULL,
                tenant_id VARCHAR(64) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id, observed_at)
            ) PARTITION BY RANGE (observed_at)
        ');

        $this->createPartitions();

        DB::statement('
            INSERT INTO proxy_audit_entries_partitioned
            SELECT * FROM proxy_audit_entries
            ON CONFLICT DO NOTHING
        ');

        Schema::dropIfExists('proxy_audit_entries');
        DB::statement('ALTER TABLE proxy_audit_entries_partitioned RENAME TO proxy_audit_entries');
    }

    public function down(): void
    {
        // No automatic down — partitioning is data-preserving
    }

    private function shouldPartition(): bool
    {
        $driver = config('database.default');

        return $driver === 'pgsql';
    }

    private function createPartitions(): void
    {
        $months = [0, 1, 2, 3, 4, 5];

        foreach ($months as $offset) {
            $start = now()->addMonths($offset)->startOfMonth()->format('Y-m-d');
            $end = now()->addMonths($offset + 1)->startOfMonth()->format('Y-m-d');
            $name = 'audit_entries_' . now()->addMonths($offset)->format('Y_m');

            DB::statement("
                CREATE TABLE IF NOT EXISTS {$name}
                PARTITION OF proxy_audit_entries_partitioned
                FOR VALUES FROM ('{$start}') TO ('{$end}')
            ");
        }
    }
};
