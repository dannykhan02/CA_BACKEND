<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additional hardening (not in the original audit): there was previously no
 * record of who viewed, downloaded, approved, rejected, or reprocessed a
 * document — only `last_updated_by` on the documents table, which gets
 * overwritten on every action and keeps no history. For a product that
 * classifies documents as Restricted/Confidential, an append-only audit
 * trail is table-stakes for a compliance investigation ("who accessed this
 * Restricted filing, and when").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action'); // e.g. 'document.view', 'document.download', 'document.approve'
            $table->string('auditable_type');
            $table->uuid('auditable_id');
            $table->json('metadata')->nullable(); // e.g. {"classification": "Restricted"}
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['auditable_type', 'auditable_id']);
            $table->index(['user_id', 'created_at']);
            $table->index('action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
