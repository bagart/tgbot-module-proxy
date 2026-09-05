<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Proxy Operations sources (plan §11.21): where inventory proxies came from
 * (paste | file | feed | manual), tenant-scoped. Feed bookkeeping carries the
 * R6.8 import key ingredient `import_policy_version` for tenant import
 * idempotency.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proxy_sources', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('tenant_id')->constrained('users')->cascadeOnDelete();
            // paste|file|feed|manual — BAGArt\ProxyOperations\Models\SourceKind.
            $table->string('kind', 20);
            $table->string('label')->nullable();
            // Present for kind=feed: external feed identifier (R6.8).
            $table->string('feed_id')->nullable();
            $table->string('import_policy_version')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamp('last_synced_at')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proxy_sources');
    }
};
