<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proxy_decisions', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id', 64)->index();
            $table->string('decision_type', 64)->index();
            $table->string('entity_type', 64);
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->string('action', 255);
            $table->text('reason')->nullable();
            $table->json('metadata')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'decision_type']);
            $table->index(['tenant_id', 'entity_type', 'entity_id']);
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proxy_decisions');
    }
};
