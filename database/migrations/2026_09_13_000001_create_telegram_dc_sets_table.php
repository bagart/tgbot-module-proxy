<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Telegram DC set snapshot — global (NOT tenant-scoped) system table.
 * Immutable once seeded; new versions are appended, never updated.
 * The version participates in ProbeCacheKeyV3 for TG probe cache invalidation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_dc_sets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedInteger('version');
            $table->unsignedTinyInteger('dc_id');
            $table->json('addresses');
            $table->json('ports');
            $table->boolean('enabled')->default(true);
            $table->text('description')->nullable();
            $table->timestamp('created_at');

            $table->unique(['version', 'dc_id']);
            $table->index('version');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_dc_sets');
    }
};
