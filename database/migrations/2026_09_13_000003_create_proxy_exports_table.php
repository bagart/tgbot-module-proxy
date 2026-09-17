<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Export audit log — tracks every export action per workspace.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proxy_exports', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('tenant_id')->constrained('users')->cascadeOnDelete();
            $table->string('export_id')->unique();
            $table->string('format');
            $table->unsignedInteger('record_count');
            $table->boolean('include_credentials')->default(false);
            $table->string('requested_by')->nullable();
            $table->timestamp('created_at');

            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proxy_exports');
    }
};
