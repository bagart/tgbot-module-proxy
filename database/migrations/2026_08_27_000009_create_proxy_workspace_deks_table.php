<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-workspace Data Encryption Keys (plan §§10.12 п.13, 11.23, task T09):
 * one row per tenant holding only the KEK-wrapped DEK — clear key material
 * never lands in storage. `key_version` records the KEK version used for
 * wrapping so a KEK rotation is a rewrap, not a rewrite of field envelopes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proxy_workspace_deks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            // One workspace = one DEK; explicit tenant_id per §11.22 even
            // though derivable — enables repository-level assertions/RLS.
            $table->foreignId('tenant_id')->constrained('users')->cascadeOnDelete();
            // EncryptedField JSON of the workspace DEK wrapped by the KEK:
            // {key_version, algorithm, nonce, ciphertext, tag}.
            $table->json('wrapped_dek');
            // KEK version used for wrapping; enables lazy read migration.
            $table->string('key_version');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('rotated_at')->nullable();

            $table->unique('tenant_id');
            $table->index('key_version');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proxy_workspace_deks');
    }
};
