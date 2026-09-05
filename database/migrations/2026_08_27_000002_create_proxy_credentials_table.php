<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Proxy Operations credential profiles (plan §11.21, §§11.2–11.3): a
 * protocol-specific credential bound (optionally, until linked) to an
 * endpoint. The plaintext secret is never persisted — only its HMAC
 * fingerprint and masked representation; the encrypted envelope column stays
 * NULL until the T09 encryptor fills it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proxy_credentials', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('tenant_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('endpoint_id')->nullable()->constrained('proxy_endpoints')->cascadeOnDelete();
            $table->string('kind', 20);
            $table->string('username')->nullable();
            // Envelope {key_version, algorithm, nonce, ciphertext, tag}, written
            // by the T09 encryptor only. A plaintext secret column MUST NOT exist.
            $table->json('secret_envelope')->nullable();
            // hex64 HMAC-SHA256 over the canonical credential payload,
            // tenant-independent value (§11.37 R6.2 — shared-cache rule).
            $table->string('fingerprint', 64);
            // e.g. "us***:***@1.2.3.4:1080" — never contains the secret.
            $table->string('masked_representation');
            $table->timestamps();

            // Duplicate-profile guard (plain unique for SQLite compatibility:
            // NULL endpoint_id/username rows stay distinct in SQLite).
            $table->unique(['tenant_id', 'endpoint_id', 'kind', 'username']);
            $table->index(['tenant_id', 'endpoint_id']);
            $table->index(['fingerprint']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proxy_credentials');
    }
};
