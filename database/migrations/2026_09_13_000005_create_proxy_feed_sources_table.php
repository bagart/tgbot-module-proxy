<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proxy_feed_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tg_bots')->cascadeOnDelete();
            $table->string('url', 2048);
            $table->string('format', 32)->default('text_line');
            $table->string('status', 32)->default('active');
            $table->string('schedule')->default('0 */6 * * *');
            $table->timestamp('last_synced_at')->nullable();
            $table->integer('sync_interval_minutes')->default(360);
            $table->json('filter_config')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index('last_synced_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proxy_feed_sources');
    }
};
