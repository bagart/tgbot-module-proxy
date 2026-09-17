<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proxy_gateway_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id', 64)->index();
            $table->string('name', 255);
            $table->string('token_hash', 64)->unique();
            $table->json('scopes')->default('["read","write"]');
            $table->unsignedInteger('rate_limit')->default(60);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proxy_gateway_tokens');
    }
};
