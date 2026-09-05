<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('raw_feed_entries', function (Blueprint $table): void {
            $table->string('batch_level_hash', 64)->nullable()->after('batch_hash');
            $table->index(['tenant_id', 'batch_level_hash']);
        });
    }

    public function down(): void
    {
        Schema::table('raw_feed_entries', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'batch_level_hash']);
            $table->dropColumn('batch_level_hash');
        });
    }
};
