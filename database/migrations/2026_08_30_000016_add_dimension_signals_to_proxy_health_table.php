<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T27 (R6.6): per-dimension health verdicts ('pass' | 'fail' | 'unknown' |
 * 'not_applicable' per evidence type) stored next to the derived scores of
 * the access-scoped health projection.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proxy_health', function (Blueprint $table): void {
            $table->json('dimension_signals')->nullable()->after('latency_percentiles');
        });
    }

    public function down(): void
    {
        Schema::table('proxy_health', function (Blueprint $table): void {
            $table->dropColumn('dimension_signals');
        });
    }
};
