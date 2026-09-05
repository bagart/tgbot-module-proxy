<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T27 (§11.29 IMPROVE#11): persisted recovery-side hysteresis counter, the
 * mirror of consecutive_failures. Kept on the access row so the anti-flap
 * counters survive worker restarts; the health evaluator (T27) is the only
 * writer besides the state machine bookkeeping.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proxy_accesses', function (Blueprint $table): void {
            $table->unsignedInteger('consecutive_successes')->default(0)->after('consecutive_failures');
        });
    }

    public function down(): void
    {
        Schema::table('proxy_accesses', function (Blueprint $table): void {
            $table->dropColumn('consecutive_successes');
        });
    }
};
