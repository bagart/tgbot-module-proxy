<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proxy_incidents', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id', 64)->index();
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->string('severity', 32)->default('info');
            $table->string('status', 32)->default('open');
            $table->string('source', 64)->nullable();
            $table->json('affected_endpoints')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'created_at']);
        });

        Schema::create('proxy_incident_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incident_id')->constrained('proxy_incidents')->cascadeOnDelete();
            $table->string('action_type', 64);
            $table->text('description');
            $table->string('performed_by', 64)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('incident_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proxy_incident_actions');
        Schema::dropIfExists('proxy_incidents');
    }
};
